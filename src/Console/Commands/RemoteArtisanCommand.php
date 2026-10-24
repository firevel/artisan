<?php

namespace Firevel\Artisan\Console\Commands;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Console\Command;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Run an artisan command on a deployed instance.
 *
 * Replacement for:
 *   curl -X POST -d "{command}" \
 *     -H "Authorization: Bearer $(gcloud auth print-access-token)" \
 *     -H "Accept: application/json" \
 *     https://{service}-dot-{project}.appspot.com/_artisan/call
 */
class RemoteArtisanCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'firevel:artisan
        {cmd* : Artisan command to run remotely (quote it, or put it after "--", to pass options)}
        {--url= : Base URL of the remote app, e.g. https://myapp.appspot.com (skips project/service detection)}
        {--project= : Google Cloud project ID (default GOOGLE_CLOUD_PROJECT, then app.yaml, then gcloud config)}
        {--service= : App Engine service name (default GAE_SERVICE, then app.yaml, then "default")}
        {--token= : Access token (default ARTISAN_REMOTE_TOKEN, then "gcloud auth print-access-token")}
        {--queue : Dispatch through the queue endpoint instead of running synchronously}
        {--y|yes : Skip the confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run an artisan command on a remote App Engine / Cloud Run instance';

    /**
     * Resolved details shown in the confirmation summary (label => rendered value).
     *
     * @var array<string, string>
     */
    protected $details = [];

    /**
     * Path to the gcloud binary. Null until searched, false when not installed.
     *
     * @var string|false|null
     */
    protected $gcloud;

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $command = $this->buildRemoteCommand($this->argument('cmd'));

        // Authentication check, unless the caller supplied a token.
        $account = null;

        if (! $this->providedToken()) {
            if (! $this->hasGcloud()) {
                $this->error('gcloud CLI not found. Install it from https://cloud.google.com/sdk/docs/install or pass --token.');

                return self::FAILURE;
            }

            $account = $this->gcloud(['auth', 'list', '--filter=status:ACTIVE', '--format=value(account)']);

            if (! $account) {
                $this->error('You are not logged in to gcloud. Run "gcloud auth login" first, or pass --token.');

                return self::FAILURE;
            }
        }

        $url = $this->resolveUrl();

        if (! $url) {
            $this->error('Could not determine the remote host. Pass --url, or --project (and --service), or set GOOGLE_CLOUD_PROJECT.');

            return self::FAILURE;
        }

        $this->printSummary($command, $url, $account);

        $host = parse_url($url, PHP_URL_HOST);

        if (! $this->option('yes') && ! $this->confirm(sprintf('Run "%s" on %s?', $command, $host))) {
            $this->line('Aborted.');

            return self::FAILURE;
        }

        $token = $this->providedToken() ?: $this->gcloudAccessToken();

        if (! $token) {
            return self::FAILURE;
        }

        return $this->send($url, $command, $token);
    }

    /**
     * Re-throw unknown-option errors with a hint about quoting the remote command.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return int
     */
    public function run(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::run($input, $output);
        } catch (RuntimeException $e) {
            if (! preg_match('/option does not exist/i', $e->getMessage())) {
                throw $e;
            }

            throw new RuntimeException(
                $e->getMessage()."\n\n"
                ."To pass options to the remote command, quote it or put it after \"--\":\n"
                ."  php artisan firevel:artisan \"migrate --force\"\n"
                ."  php artisan firevel:artisan -- migrate --force"
            );
        }
    }

    /**
     * Build the command string sent to the remote instance.
     *
     * A single token is sent verbatim (e.g. "migrate --force" passed as one quoted
     * argument). Multiple tokens (from the "--" form) are re-quoted where needed so
     * the remote StringInput parser sees the same arguments.
     *
     * @param  array<int, string>  $tokens
     * @return string
     */
    protected function buildRemoteCommand(array $tokens): string
    {
        if (count($tokens) === 1) {
            return trim($tokens[0]);
        }

        return implode(' ', array_map(function (string $token) {
            if ($token !== '' && ! preg_match('/[\s"\'\\\\]/', $token)) {
                return $token;
            }

            return '"'.addcslashes($token, '"\\').'"';
        }, $tokens));
    }

    /**
     * Token supplied explicitly through --token or ARTISAN_REMOTE_TOKEN.
     *
     * @return string|null
     */
    protected function providedToken(): ?string
    {
        return $this->option('token') ?: config('artisan.remote.token') ?: null;
    }

    /**
     * Resolve the full endpoint URL.
     *
     * @return string|null
     */
    protected function resolveUrl(): ?string
    {
        if ($url = $this->option('url')) {
            $this->detail('Host', $url, '--url');

            return $this->endpoint($url);
        }

        $explicitTarget = $this->option('project') || $this->option('service');

        if (! $explicitTarget && ($url = config('artisan.remote.url'))) {
            $this->detail('Host', $url, 'ARTISAN_REMOTE_URL');

            return $this->endpoint($url);
        }

        $project = $this->resolveProject();

        if (! $project) {
            return null;
        }

        $service = $this->resolveService();

        $host = $service === 'default'
            ? "{$project}.appspot.com"
            : "{$service}-dot-{$project}.appspot.com";

        return $this->endpoint('https://'.$host);
    }

    /**
     * Append the artisan route to a base URL.
     *
     * @param  string  $base
     * @return string
     */
    protected function endpoint(string $base): string
    {
        $base = rtrim(trim($base), '/');

        if (! preg_match('#^https?://#i', $base)) {
            $base = 'https://'.$base;
        }

        $prefix = trim(config('artisan.route.prefix', '_artisan'), '/');

        // Tolerate a base URL that already includes the route prefix.
        if (str_ends_with($base, '/'.$prefix)) {
            $base = substr($base, 0, -strlen('/'.$prefix));
        }

        return $base.'/'.$prefix.'/'.($this->option('queue') ? 'queue' : 'call');
    }

    /**
     * Resolve the Google Cloud project ID.
     *
     * @return string|null
     */
    protected function resolveProject(): ?string
    {
        if ($project = $this->option('project')) {
            $this->detail('Project', $project, '--project');

            return $project;
        }

        if ($project = config('artisan.remote.project')) {
            $this->detail('Project', $project, 'GOOGLE_CLOUD_PROJECT');

            return $project;
        }

        if ($project = $this->appYamlEnvVariable('GOOGLE_CLOUD_PROJECT')) {
            $this->detail('Project', $project, 'app.yaml');

            return $project;
        }

        if ($this->hasGcloud() && ($project = $this->gcloud(['config', 'get-value', 'project']))) {
            $this->detail('Project', $project, 'gcloud config');

            return $project;
        }

        return null;
    }

    /**
     * Resolve the App Engine service name.
     *
     * @return string
     */
    protected function resolveService(): string
    {
        if ($service = $this->option('service')) {
            $this->detail('Service', $service, '--service');

            return $service;
        }

        if ($service = config('artisan.remote.service')) {
            $this->detail('Service', $service, 'GAE_SERVICE');

            return $service;
        }

        if ($service = $this->appYamlService()) {
            $this->detail('Service', $service, 'app.yaml');

            return $service;
        }

        $this->detail('Service', 'default', 'default');

        return 'default';
    }

    /**
     * Read app.yaml from the project root, if present.
     *
     * @return string|null
     */
    protected function appYaml(): ?string
    {
        $path = base_path('app.yaml');

        return is_file($path) ? (string) file_get_contents($path) : null;
    }

    /**
     * Top-level "service:" value from app.yaml.
     *
     * @return string|null
     */
    protected function appYamlService(): ?string
    {
        $yaml = $this->appYaml();

        if ($yaml && preg_match('/^service:[ \t]*["\']?([A-Za-z0-9-]+)/m', $yaml, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Value of an indented key (env_variables entry) from app.yaml.
     *
     * @param  string  $key
     * @return string|null
     */
    protected function appYamlEnvVariable(string $key): ?string
    {
        $yaml = $this->appYaml();

        if ($yaml && preg_match('/^[ \t]+'.preg_quote($key, '/').':[ \t]*["\']?([^"\'\s#]+)/m', $yaml, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Whether the gcloud CLI is installed.
     *
     * @return bool
     */
    protected function hasGcloud(): bool
    {
        if ($this->gcloud === null) {
            $this->gcloud = (new ExecutableFinder)->find('gcloud') ?? false;
        }

        return $this->gcloud !== false;
    }

    /**
     * Run a gcloud command and return trimmed stdout, or null on failure / empty output.
     *
     * @param  array<int, string>  $arguments
     * @param  string|null  $error  Receives trimmed stderr.
     * @return string|null
     */
    protected function gcloud(array $arguments, ?string &$error = null): ?string
    {
        $process = new Process(array_merge([$this->gcloud], $arguments));
        $process->setTimeout(60);
        $process->run();

        $error = trim($process->getErrorOutput());

        if (! $process->isSuccessful()) {
            return null;
        }

        $output = trim($process->getOutput());

        return $output === '' ? null : $output;
    }

    /**
     * Mint an access token for the active gcloud account.
     *
     * @return string|null
     */
    protected function gcloudAccessToken(): ?string
    {
        $token = $this->gcloud(['auth', 'print-access-token'], $error);

        if (! $token) {
            $this->error('Could not get an access token from gcloud. Try "gcloud auth login".');

            if ($error) {
                $this->line($error);
            }
        }

        return $token;
    }

    /**
     * Record a resolved value and where it came from.
     *
     * @param  string  $label
     * @param  string  $value
     * @param  string  $source
     * @return void
     */
    protected function detail(string $label, string $value, string $source): void
    {
        $this->details[$label] = OutputFormatter::escape($value).' <fg=gray>('.$source.')</>';
    }

    /**
     * Print what is about to happen.
     *
     * @param  string  $command
     * @param  string  $url
     * @param  string|null  $account
     * @return void
     */
    protected function printSummary(string $command, string $url, ?string $account): void
    {
        $rows = ['Command' => OutputFormatter::escape($command)] + $this->details;
        $rows['Endpoint'] = OutputFormatter::escape($url);

        if ($account) {
            $rows['Account'] = OutputFormatter::escape($account).' <fg=gray>(gcloud)</>';
        } elseif ($this->option('token')) {
            $rows['Account'] = '<fg=gray>token from --token</>';
        } else {
            $rows['Account'] = '<fg=gray>token from ARTISAN_REMOTE_TOKEN</>';
        }

        $width = max(array_map('strlen', array_keys($rows)));

        $this->newLine();

        foreach ($rows as $label => $value) {
            $this->line(sprintf('  <info>%-'.$width.'s</info>  %s', $label, $value));
        }

        $this->newLine();
    }

    /**
     * Send the command to the remote instance and print the response.
     *
     * @param  string  $url
     * @param  string  $command
     * @param  string  $token
     * @return int
     */
    protected function send(string $url, string $command, string $token): int
    {
        $header = config('artisan.authorization_header', 'Authorization');

        $client = new Client([
            'timeout' => (float) config('artisan.remote.timeout', 600),
            'connect_timeout' => 10,
            'http_errors' => false,
        ]);

        try {
            $response = $client->post($url, [
                'headers' => [
                    $header => $header === 'Authorization' ? 'Bearer '.$token : $token,
                    'Accept' => 'application/json',
                    'Content-Type' => 'text/plain',
                ],
                'body' => $command,
            ]);
        } catch (GuzzleException $e) {
            $this->error('Request failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status >= 200 && $status < 300) {
            $this->output->write($body, false, OutputInterface::OUTPUT_RAW);

            if ($body !== '' && ! str_ends_with($body, "\n")) {
                $this->output->writeln('');
            }

            return self::SUCCESS;
        }

        $this->error("Remote responded with HTTP {$status}.");

        // Laravel wraps HttpException messages as {"message": "..."} for JSON clients.
        $decoded = json_decode($body, true);
        $message = is_array($decoded) && ! empty($decoded['message']) ? $decoded['message'] : $body;

        if ($message !== '') {
            $this->output->writeln($message, OutputInterface::OUTPUT_RAW);
        }

        if ($status === 401) {
            $this->line('<comment>Hint:</comment> the access token was rejected. Run "gcloud auth login" and try again.');
        } elseif ($status === 403) {
            $this->line('<comment>Hint:</comment> the remote accepts access tokens from accounts holding the "cloudscheduler.jobs.run" or "appengine.runtimes.actAsAdmin" permission on the project.');
        }

        return self::FAILURE;
    }
}

<?php

namespace Firevel\Artisan\Http\Requests;

use Exception;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Http\FormRequest;
use Google\Auth\AccessToken;

class ArtisanRequest extends FormRequest
{
    /**
     * AppEngine IPs.
     *
     * Ref.: https://cloud.google.com/appengine/docs/flexible/nodejs/scheduling-jobs-with-cron-yaml#validating_cron_requests
     *
     * @var array
     */
    protected $appengineCronIPs = [
        '0.1.0.2',
        '0.1.0.1', // older gcloud versions (earlier than 326.0.0)
    ];

    /**
     * List of Google Cloud permissions required to execute task.
     * 
     * Only one permission is required so its "or" list.
     *
     * @var array
     */
    protected $requiredPermissions = [
        'cloudscheduler.jobs.run',
        'appengine.runtimes.actAsAdmin',
    ];

    /**
     * AppEngine cron header.
     *
     * Ref.: https://cloud.google.com/appengine/docs/flexible/nodejs/scheduling-jobs-with-cron-yaml#validating_cron_requests
     *
     * @var string
     */
    protected $appEngineCronHeader = 'x-appengine-cron';

    /**
     * Project resource manager endpoint.
     *
     * @var string
     */
    protected $resourceManagerEndpoint = 'https://cloudresourcemanager.googleapis.com/v3/projects/';

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        // Check if service is running inside AppEngine
        if (! empty($_SERVER['GAE_SERVICE'])) {
            // Check called from App Engine cron.
            if (
                $this->hasHeader($this->appEngineCronHeader) &&
                in_array($this->header('x-appengine-user-ip'), $this->appengineCronIPs)
            ) {
                return true;
            }

            // Check if call was initiated by cloud scheduler.
            if (
                $this->hasHeader('x-cloudscheduler') &&
                $this->hasHeader('x-google-internal-skipadmincheck')
            ) {
                return true;
            }
        }

        $token = $this->getToken();

        if (empty($token)) {
            return false;
        }

        // Check if token is JWT.
        if ($this->isValidJwt($token)) {
            return $this->verifyJwtToken($token);
        }

        // If not AppEngine Cron or Cloud Scheduler - proceed with Access Token Verification
        return $this->verifyAccessToken($this->getToken());
    }

    /**
     * Veritfy if JWT token is authorized.
     *
     * @param  string  $token
     * @return bool
     */
    public function verifyJwtToken($token)
    {
        // Fetch Google's public keys
        $publicKeysJson = file_get_contents('https://www.googleapis.com/oauth2/v3/certs');
        $publicKeys = JWK::parseKeySet(json_decode($publicKeysJson, true));

        // Verify the ID token
        try {
            $decodedToken = JWT::decode($token, $publicKeys, ['RS256']);
            $email = $decodedToken->email;

            if (empty($email)) {
                return false;
            }

            return in_array($email, config('artisan.authorized_service_accounts', []));
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Is string a valid Jwt.
     *
     * @param  string  $jwtString
     * @return boolean
     */
    public function isValidJwt($jwtString) {
        $jwtParts = explode('.', $jwtString);

        if (count($jwtParts) !== 3) {
            return false;
        }

        try {
            $header = JWT::urlsafeB64Decode($jwtParts[0]);
            $payload = JWT::urlsafeB64Decode($jwtParts[1]);

            $headerDecoded = json_decode($header);
            $payloadDecoded = json_decode($payload);

            return $headerDecoded !== null && $payloadDecoded !== null;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Verify if Google Access Token generated with "gcloud auth print-access-token" got permissions to execute call.
     * 
     * @return bool
     */
    public function verifyAccessToken($token)
    {
        $client = new \GuzzleHttp\Client();

        $response = $client->request(
            'POST',
            $this->resourceManagerEndpoint . env('GOOGLE_CLOUD_PROJECT') . ':testIamPermissions',
            [
                'headers' => [
                    'Accept'        => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                ],
                'json'    => (object) ["permissions" => $this->requiredPermissions],
            ]
        );

        return ! empty(json_decode($response->getBody(), true));
    }

    /**
     * Get identity token.
     *
     * @return string
     */
    public function getToken()
    {
        if (config('artisan.authorization_header') == 'Authorization' && ! empty($this->bearerToken())) {
            return $this->bearerToken();
        }

        return $this->header(config('artisan.authorization_header'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            //
        ];
    }
}

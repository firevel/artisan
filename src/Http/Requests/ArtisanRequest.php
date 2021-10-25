<?php

namespace Firevel\Artisan\Http\Requests;

use Exception;
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

        // If not AppEngine Cron or Cloud Scheduler - proceed with Access Token Verification
        if (empty($this->getToken())) {
            return false;
        }

        return $this->verifyAccessToken($this->getToken());
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

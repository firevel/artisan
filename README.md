# Serverless Artisan

Artisan support for Laravel / Firevel running on App Engine or Google Cloud Run. It can be used for remote command execution or Cloud Scheduler.

## Installation

Install package:

```bash
composer require firevel/artisan
```

Publish config:

```bash
php artisan vendor:publish --provider="Firevel\Artisan\ArtisanServiceProvider" --tag="config"
```

## Usage

With [Google Cloud SDK](https://cloud.google.com/sdk/docs/install) you can execute artisan commands directly from your command line. Make sure your user got `cloudscheduler.jobs.run` or `appengine.runtimes.actAsAdmin` permission.

After replacing `{command}` with artisan command (ex.: `route:list`) and {project} with your project name you can run:

```bash
curl -X POST -d "{command}" -H "Authorization: Bearer $(gcloud auth print-access-token)" https://{project}.appspot.com/_artisan/call
```

If you are running multiple services, replace {service} with your service name and run:
```bash
curl -X POST -d "{command}" -H "Authorization: Bearer $(gcloud auth print-access-token)" https://{service}-dot-{project}.appspot.com/_artisan/call
```

## Using queues

If you would like to use queues to run your commands, you would need to set `ARTISAN_CONNECTION` and `ARTISAN_QUEUE` env variables first.

Default connection is set to `cloudtasks` and default queue is set to `artisan`. If you would like to use default configuration make sure:
- [Cloud Tasks queue driver](https://packagist.org/packages/firevel/cloud-tasks-queue-driver) is installed.
- `artisan` queue is created in [Cloud Tasks console](https://console.cloud.google.com/cloudtasks). You can create queue by running `gcloud tasks queues create artisan --max-attempts=1`.

To dispatch command run:
```bash
curl -X POST -d "{command}" -H "Authorization: Bearer $(gcloud auth print-access-token)" https://{project}.appspot.com/_artisan/queue
```
or
```bash
curl -X POST -d "{command}" -H "Authorization: Bearer $(gcloud auth print-access-token)" https://{service}-dot-{project}.appspot.com/_artisan/queue
```

## Google Cloud Scheduler

You can use this package to run commands using Cloud Scheduler.

### Cloud Run
Add a job via the [Cloud Scheduler](https://console.cloud.google.com/cloudscheduler) page in the Google Cloud console. To begin, select the Target Type as `HTTP`, followed by specifying the URL field as `https://{APP_URL}/_artisan/call`, method `POST`, and the appropriate artisan command in the Body field (e.g., `route:cache`). For the Auth header, select "Add OICD token", and for the service account, select the default App Engine account. If you prefer to use a different service account, you will need to add the service account email to the configuration file under `artisan.authorized_service_accounts`.

### App Engine
If you are using App Engine you can use standard [cron.yaml](https://cloud.google.com/appengine/docs/standard/scheduling-jobs-with-cron-yaml) file.

## Security

[Request validation](https://github.com/firevel/artisan/blob/master/src/Http/Requests/ArtisanRequest.php) is based on:
- `GAE_SERVICE` env variable with `x-appengine-cron`, `x-google-internal-skipadmincheck`, `x-cloudscheduler` and `x-appengine-cron` header
- or [OIDC](https://developers.google.com/identity/protocols/OpenIDConnect) token validation if bearer token is JWT.
- otherwise it will validate bearer token using [testIamPermissions](https://cloud.google.com/resource-manager/reference/rest/v3/folders/testIamPermissions)

### Warning
If you are using this package outside App Engine make sure `GAE_SERVICE` env is NOT set.

<?php

use App\Http\Controllers\Controller;
use App\Services\Delivery\Contracts\ChannelRateLimiter;
use App\Services\Delivery\Contracts\CircuitBreaker;
use App\Services\Delivery\Contracts\DeliveryChannel;
use App\Services\Delivery\Contracts\NotificationGateway;
use App\Services\Observability\Contracts\HealthCheck;
use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\Notification;
use Illuminate\Support\ServiceProvider;

arch()->preset()->php();

arch()->preset()->security();

arch('debugging helpers are not left in the code')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();

arch('environment variables are read only in config files')
    ->expect('env')
    ->not->toBeUsed();

arch('data objects are final readonly classes with a Data suffix')
    ->expect('App\Data')
    ->toBeFinal()
    ->toBeReadonly()
    ->toHaveSuffix('Data');

arch('data objects and enums do not depend on the framework')
    ->expect(['App\Data', 'App\Enums'])
    ->not->toUse('Illuminate');

arch('enums are string backed')
    ->expect('App\Enums')
    ->toBeStringBackedEnums();

arch('enums live only in the enums namespace')
    ->expect('App')
    ->not->toBeEnums()
    ->ignoring('App\Enums');

arch('actions are classes with a handle method and an Action suffix')
    ->expect('App\Actions')
    ->toBeClasses()
    ->toHaveMethod('handle')
    ->toHaveSuffix('Action');

arch('controllers extend the base controller')
    ->expect('App\Http\Controllers')
    ->classes()
    ->toExtend(Controller::class)
    ->ignoring(Controller::class);

arch('controllers have a Controller suffix')
    ->expect('App\Http\Controllers')
    ->toHaveSuffix('Controller');

arch('controllers expose only invokable or resource actions')
    ->expect('App\Http\Controllers')
    ->not->toHavePublicMethodsBesides(['__construct', '__invoke', 'index', 'show', 'create', 'store', 'edit', 'update', 'destroy', 'middleware']);

arch('form requests extend FormRequest')
    ->expect('App\Http\Requests')
    ->classes()
    ->toExtend(FormRequest::class);

arch('form requests have a Request suffix')
    ->expect('App\Http\Requests')
    ->classes()
    ->toHaveSuffix('Request');

arch('concerns are traits')
    ->expect(['App\Http\Requests\Concerns', 'App\Rules\Concerns'])
    ->toBeTraits();

arch('API resources extend JsonResource and have a Resource suffix')
    ->expect('App\Http\Resources')
    ->toExtend(JsonResource::class)
    ->toHaveSuffix('Resource');

arch('HTTP middleware has a handle method')
    ->expect('App\Http\Middleware')
    ->toHaveMethod('handle');

arch('the HTTP layer is used only by itself and the service providers')
    ->expect('App\Http')
    ->toOnlyBeUsedIn(['App\Http', 'App\Providers']);

arch('validation rules implement ValidationRule')
    ->expect('App\Rules')
    ->classes()
    ->toImplement(ValidationRule::class);

arch('validation rules have a Rule suffix')
    ->expect('App\Rules')
    ->classes()
    ->toHaveSuffix('Rule');

arch('console commands extend Command, have a handle method and a Command suffix')
    ->expect('App\Console\Commands')
    ->toExtend(Command::class)
    ->toHaveMethod('handle')
    ->toHaveSuffix('Command');

arch('jobs are queued')
    ->expect('App\Jobs')
    ->classes()
    ->toImplement(ShouldQueue::class)
    ->ignoring('App\Jobs\Middleware');

arch('jobs have a Job suffix')
    ->expect('App\Jobs')
    ->toHaveSuffix('Job')
    ->ignoring('App\Jobs\Middleware');

arch('job middleware has a handle method')
    ->expect('App\Jobs\Middleware')
    ->toHaveMethod('handle');

arch('models extend the Eloquent model')
    ->expect('App\Models')
    ->classes()
    ->toExtend(Model::class);

arch('Eloquent models live only in the models namespace')
    ->expect('App')
    ->not->toExtend(Model::class)
    ->ignoring('App\Models');

arch('policies have a Policy suffix')
    ->expect('App\Policies')
    ->toHaveSuffix('Policy');

arch('notifications extend Notification and have a Notification suffix')
    ->expect('App\Notifications')
    ->toExtend(Notification::class)
    ->toHaveSuffix('Notification');

arch('exceptions are throwable and have an Exception suffix')
    ->expect('App\Exceptions')
    ->toImplement(Throwable::class)
    ->toHaveSuffix('Exception');

arch('service providers extend ServiceProvider and have a ServiceProvider suffix')
    ->expect('App\Providers')
    ->toExtend(ServiceProvider::class)
    ->toHaveSuffix('ServiceProvider');

arch('service classes are final')
    ->expect('App\Services')
    ->classes()
    ->toBeFinal();

arch('service contracts are interfaces')
    ->expect(['App\Services\Delivery\Contracts', 'App\Services\Observability\Contracts'])
    ->toBeInterfaces();

arch('service implementations implement the contract they are grouped under', function (string $namespace, string $contract, string $suffix) {
    expect($namespace)
        ->toImplement($contract)
        ->toHaveSuffix($suffix);
})->with([
    'delivery channels' => ['App\Services\Delivery\Channels', DeliveryChannel::class, 'Channel'],
    'notification gateways' => ['App\Services\Delivery\Gateways', NotificationGateway::class, 'Gateway'],
    'rate limiters' => ['App\Services\Delivery\RateLimiting', ChannelRateLimiter::class, 'RateLimiter'],
    'circuit breakers' => ['App\Services\Delivery\CircuitBreaking', CircuitBreaker::class, 'CircuitBreaker'],
    'health checks' => ['App\Services\Observability\HealthChecks', HealthCheck::class, 'HealthCheck'],
]);

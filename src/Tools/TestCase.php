<?php

namespace TestHelper\Tools;

use Throwable;
use PHPUnit\Util\Test;
use BadMethodCallException;
use Illuminate\Http\Request;
use Faker\Generator as Faker;
use UnexpectedValueException;
use Illuminate\Routing\Router;
use Illuminate\Config\Repository;
use Illuminate\Support\{Arr, Env};
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\Exceptions\Handler;
use Laravel\Passport\PassportServiceProvider;
use Illuminate\Validation\ValidationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Illuminate\Contracts\Routing\{BindingRegistrar, Registrar};

/**
 * Class TestCase
 * @package TestHelper\Tests
 */
class TestCase extends BaseTestCase
{
    use ResponseHelpers, AnonymousResponse, TokenHelpers;

    /**
     * @var Faker
     */
    protected $faker;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        parent::setUp();
//        $this->artisan('config:clear');
//        $this->artisan('vendor:publish', ['--all' => true, '--force' => true]);

        static::generateAppKey($this->app);
        $this->faker = app(Faker::class);
    }
    
    protected function getEnvironmentSetUp($app)
    {
        $envPath = __DIR__ . '/..';
        $possibleEnvs = ['.env.testing', '.env', '.env.example'];
        $findEnv = fn (string $file, string $name) => $file ?: (is_file("$envPath/$name") ? $name : '');
        $file = array_reduce($possibleEnvs, $findEnv, '');
        $file && Dotenv::create(Env::getRepository(), $envPath, $file)->safeLoad();
        $app->make(LoadConfiguration::class)->bootstrap($app);
    }

    /**
     * @param Application $app
     * @return string
     */
    public static function generateAppKey(Application $app): string
    {
        /** @var Repository $config singleton */
        $config = $app->make('config');

        if ($key = !$config->get('app.key')) {
            $key = 'base64:' . base64_encode(Encrypter::generateKey($config->get('app.cipher')));
            $config->set('app.key', $key);
        }

        return $key;
    }

    /**
     * @param Application $app
     */
    protected function resolveApplicationExceptionHandler($app)
    {
        $app->singleton(ExceptionHandler::class, function (...$args) {
            return new class(...$args) extends Handler
            {
                /**
                 * @var string[]
                 */
                protected $dontReport = [];

                /**
                 * @param Request $request
                 * @param Throwable $e
                 * @return mixed
                 */
                public function render($request, Throwable $e)
                {
                    $request->headers->set('Accept', 'application/json');

                    $debug = $e instanceof ValidationException ? [
                        'errors' => $e->errors(),
                        'data' => $e->validator->getData()
                    ] : [];

                    $trace = array_map(function (array $step) {
                        $step = array_merge([
                            'file' => '',
                            'line' => 0,
                            'function' => '',
                            'class' => '',
                            'args' => []
                        ], $step);

                        $args = array_map(function ($arg) {
                            if (is_object($arg)) {
                                return get_class($arg);
                            } elseif (is_resource($arg)) {
                                return 'resource';
                            } elseif (is_array($arg)) {
                                return 'array('.count($arg).')';
                            } elseif (is_string($arg)) {
                                return '"'.substr($arg, 0, $n = 50).(strlen($arg) > $n ? '...' : '').'"';
                            } elseif (is_null($arg)) {
                                return 'null';
                            } elseif (is_bool($arg)) {
                                return $arg ? 'true' : 'false';
                            } elseif (is_numeric($arg)) {
                                return $arg;
                            }

                            throw new UnexpectedValueException('Unhandled type: '.gettype($arg));
                        }, $step['args']);

                        return [
                            'location' => $step['file'].':'.$step['line'],
                            'function' => implode('::', array_filter([$step['class'], $step['function']]))
                                .'('.implode(', ', $args).')'
                        ];
                    }, $e->getTrace());

                    return parent::render($request, $e)->setContent(json_encode([
                        'type' => get_class($e),
                        'code' => $e->getCode(),
                        'message' => $e->getMessage(),
                        'debug' => $debug,
                        'trace' => $trace
                    ]));
                }
            };
        });
    }

    /**
     * This only seems to be an issue when run in GitLab CI Jobs
     * @inheritDoc
     * @link https://github.com/orchestral/testbench/issues/132#issuecomment-252438072 IMS Global Documentation
     */
    protected function getPackageAliases($app)
    {
        return [
            'routes' => [Router::class, Registrar::class, BindingRegistrar::class]
        ];
    }

    /**
     * @inheritDoc
     */
    protected function getPackageProviders($app)
    {
        $composerConfig = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);

        return Arr::get($composerConfig, 'extra.laravel.providers');
    }

    /**
     * Address compatibility issues between Orchestra\Testbench and PHPUnit\Framework.
     * @param string $name
     * @param array $arguments
     * @return array
     */
    public function __call(string $name, array $arguments = [])
    {
        if ($name == 'getAnnotations') {
            return Test::parseTestMethodAnnotations(static::class, $this->getName());
        }

        throw new BadMethodCallException("Undefined method '$name'.");
    }
}

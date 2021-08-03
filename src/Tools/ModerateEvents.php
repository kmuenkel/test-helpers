<?php

namespace TestHelper\Tools;

use Mockery;
use ReflectionFunction;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\{Arr, Collection};
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Events\Dispatcher as DispatcherContract;
use Illuminate\Foundation\Testing\Concerns\MocksApplicationServices;

class ModerateEvents implements Arrayable
{
    use MocksApplicationServices;

    /**
     * @var array
     */
    protected $eventArgs = [];

    /**
     * @var array
     */
    protected $eventBinding = [];

    /**
     * @var array
     */
    protected $actions = [];

    /**
     * Limit the scope of events and/or listeners to be fired during this test to avoid collateral actions.
     * This is just a filter.  Therefore, if something is mis-configured and the expected actions would not take place,
     * this method will not erroneously fire them.
     *
     * @param callable $trigger
     * @param string $targetEvent
     * @param string $targetListener
     * @return array $event => [$listeners] fired by the $trigger within the bounds of $targetEvent and $targetListener
     */
    public function firingScope(callable $trigger, string $targetEvent = '*', string $targetListener = '*'): array
    {
        $this->cherryPickEvents(function () use ($trigger) {
            $trigger(); //withoutEvents mocks the Dispatcher, so calling the trigger will populate $this->firedEvents
        }); //Remember the original binding and temporarily deactivate all events

        return $this->fireEvents($targetEvent, $targetListener)->getActions();
    }

    /**
     * @param string $targetEvent
     * @param string $targetListener
     * @return $this
     */
    public function fireEvents(string $targetEvent = '*', string $targetListener = '*'): self
    {
        collect($this->firedEvents)->each(function ($event, $index) use ($targetEvent, $targetListener) {
            $eventName = is_string($event) ? $event : get_class($event);
            $listeners = $this->getListeners($eventName);   //Record the attached listeners before forgetting them
            Event::forget($eventName);  //Remove listeners to avoid collateral actions.

            if (fnmatch(addslashes($targetEvent), $eventName)) {   //If the event in question is our $targetEvent...
                $this->actions[$eventName] = ['listeners' => []];

                foreach ($listeners as $listener) { //...re-apply listeners a-la-carte based on the $targetListener
                    if ((is_string($listener) && fnmatch(addslashes($targetListener), $listener)) //Allow wildcards
                        || $listener == $targetListener //The listener may be a closure rather than a class name
                        || $targetListener == '*'
                    ) {
                        $this->actions[$eventName]['listeners'] = $listener;
                        Event::listen($eventName, $listener);   //Allow only the target listeners to fire
                    }
                }

                $args = $this->eventArgs[$index];   //Any arguments passed to the event will be in a parallel array
                event($event, $args);  //Allow the original $targetEvent instance to fire normally now
            }
        });

        return $this;    //Return the discovered events and listeners, filtered by the targets for assertions later
    }

    /**
     * @return array
     */
    public function getFiredEvents(): array
    {
        return $this->firedEvents;
    }

    /**
     * @return array
     */
    public function getActions(): array
    {
        return $this->actions;
    }

    /**
     * Override the Mock-event-dispatcher so it records additional arguments in addition to the event name
     *
     * @param callable $action
     * @param bool $reset
     */
    public function cherryPickEvents(callable $action, bool $reset = false)
    {
        $this->deactivateEvents();

        $action();

        $this->reactivateEvents($reset);
    }

    /**
     * @void
     */
    public function deactivateEvents()
    {
        if (!empty($this->eventBinding)) {
            return;
        }

        $originalBinding = Arr::get(app()->getBindings(), 'events', []);
        $resolved = false;
        if (Arr::get($originalBinding, 'shared') && $resolved = app()->resolved('events')) {
            $originalBinding = app('events');
        }
        $this->eventBinding = [
            'binding' => $originalBinding,
            'resolved' => $resolved
        ];

        /** @var Dispatcher|Mockery\Mock $mock */
        $mock = Mockery::mock(DispatcherContract::class)->shouldIgnoreMissing();

        $mock->shouldReceive('fire', 'dispatch', 'until', 'listen')->andReturnUsing(function ($called, $args = []) {
            $this->firedEvents[] = $called;
            end($this->firedEvents);
            $index = key($this->firedEvents);
            reset($this->firedEvents);

            if (is_object($args)) {
                /** @var Model $args */
                $args = clone $args;
            } elseif (is_array($args)) {
                foreach ($args as $i => $arg) {
                    if (is_object($arg)) {
                        $args[$i] = clone $arg;
                    }
                }
            }

            $this->eventArgs[$index] = $args;
        });

        app()->instance('events', $mock);
        Model::setEventDispatcher($mock);
    }

    /**
     * @param bool $reset
     */
    public function reactivateEvents(bool $reset = false)
    {
        if (empty($this->eventBinding)) {
            return;
        }

        if ($this->eventBinding['resolved']) {
            app()->instance('events', $this->eventBinding['binding']);
            Model::setEventDispatcher($this->eventBinding['binding']);
        } else {
            app()->offsetUnset('events');
            $concrete = Arr::get($this->eventBinding['binding'], 'concrete');
            $shared = Arr::get($this->eventBinding['binding'], 'shared', false);
            app()->bind('events', $concrete, $shared);
        }

        if ($reset) {
            $this->eventArgs = $this->firedEvents = [];
        }

        $this->eventBinding = [];
    }

    /**
     * @param string $eventName
     * @return Collection
     */
    public function getListeners(string $eventName): Collection
    {
        return collect(Event::getListeners($eventName))->map(function ($listener) {
            $staticVariables = (new ReflectionFunction($listener))->getStaticVariables();

            return collect($staticVariables)->only('listener')->first();
        });
    }

    /**
     * @inheritDoc
     */
    public function toArray()
    {
        return collect($this->firedEvents)->mapWithKeys(function ($event, $index) {
            $eventName = is_string($event) ? $event : get_class($event);

            return [$index => $eventName];
        })->toArray();
    }
}

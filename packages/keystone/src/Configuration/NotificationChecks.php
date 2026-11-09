<?php

namespace ClaudioDekker\Keystone\Configuration;

use ClaudioDekker\Keystone\Models\UserSecurityEvent;
use ClaudioDekker\Keystone\Notifications\SecurityAlert;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Notifications\Notification;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

/**
 * @internal
 */
class NotificationChecks
{
    /**
     * Create the checks.
     */
    public function __construct(
        protected Config $config,
    ) {
        //
    }

    /**
     * Get the failure of each slot that would lose every alert of its type.
     *
     * @return list<string>
     */
    public function failures(): array
    {
        $slots = $this->config->get('keystone.notifications');

        if (! is_array($slots) || array_is_list($slots)) {
            return ['keystone.notifications must map security event types to a notification class or null.'];
        }

        $failures = [];

        foreach ($slots as $type => $class) {
            $failures[] = $this->failure((string) $type, $class);
        }

        return array_values(array_filter($failures));
    }

    /**
     * Get the failure of the type's slot, if it has one.
     */
    protected function failure(string $type, mixed $class): ?string
    {
        $eventType = SecurityEventType::tryFrom($type);

        if ($eventType === null) {
            return "keystone.notifications names [{$type}], which is no security event type.";
        }

        if ($class === null) {
            return null;
        }

        if (! is_string($class) || ! is_subclass_of($class, Notification::class)) {
            return "keystone.notifications.{$type} must be null or a notification class.";
        }

        if (! $this->isBuiltFromTheEvent($class)) {
            return "keystone.notifications.{$type} names [{$class}], whose constructor must take the security event alone.";
        }

        if ($class === SecurityAlert::class && ! SecurityAlert::hasMail($eventType)) {
            return "keystone.notifications.{$type} names SecurityAlert, which has no mail for that type. Name a notification class of the app's own.";
        }

        return null;
    }

    /**
     * Determine if the class can be built from the event alone, as the recorder builds it.
     *
     * @param  class-string<Notification>  $class
     */
    protected function isBuiltFromTheEvent(string $class): bool
    {
        $reflection = new ReflectionClass($class);
        $parameters = $reflection->getConstructor()?->getParameters() ?? [];
        $required = array_filter($parameters, fn (ReflectionParameter $parameter) => ! $parameter->isOptional());

        if (count($required) > 1) {
            return false;
        }

        return $parameters === [] || $this->acceptsTheEvent($parameters[0]->getType());
    }

    /**
     * Determine if a parameter of the type accepts a security event.
     */
    protected function acceptsTheEvent(?ReflectionType $type): bool
    {
        return match (true) {
            $type === null => true,
            $type instanceof ReflectionNamedType => $this->namesTheEvent($type),
            $type instanceof ReflectionUnionType => array_any($type->getTypes(), $this->acceptsTheEvent(...)),
            $type instanceof ReflectionIntersectionType => array_all($type->getTypes(), $this->acceptsTheEvent(...)),
            default => false,
        };
    }

    /**
     * Determine if the named type is the event's class, one it extends or implements, or any object.
     */
    protected function namesTheEvent(ReflectionNamedType $type): bool
    {
        $name = $type->getName();

        return in_array($name, ['mixed', 'object'], true)
            || is_a(UserSecurityEvent::class, $name, true);
    }
}

<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Infrastructure\Messenger;

/**
 * The classes a queued message may be made of.
 *
 * TYPO3's messenger serializer unserializes a row of sys_messenger_messages
 * with every class allowed, so whoever can write that row chooses the
 * objects the consumer instantiates, with the umbrella's whole vendor tree as
 * gadget supply. The queue is a trust boundary: an INSERT there is code
 * execution in the consumer. This list keeps it to the classes a message of
 * this extension is made of -- the two message types, Symfony's envelope and
 * stamps, a mail and its parts, the dates in its headers, and the flattened
 * exception a parked message carries.
 *
 * A site that routes a message of its own to one of these transports adds
 * its class (or namespace prefix) to the list:
 *
 *   messageClassPrefixes  further class-name prefixes, as PHP list or
 *                         comma-separated string; default none
 *
 * under $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['koh_async'].
 */
final class QueueClassAllowlist
{
    /** @var list<string> */
    public const DEFAULT_PREFIXES = [
        'Koh\Typo3Async\Infrastructure\Image\PregenerateImageVariants',
        'Symfony\Component\Messenger\Envelope',
        'Symfony\Component\Messenger\Stamp\\',
        'Symfony\Component\Mailer\Messenger\SendEmailMessage',
        'Symfony\Component\Mailer\Envelope',
        'Symfony\Component\Mailer\Header\\',
        'Symfony\Component\Mime\\',
        'TYPO3\CMS\Core\Mail\\',
        'Symfony\Component\ErrorHandler\Exception\FlattenException',
        'DateTime',
        'DateTimeImmutable',
        'DateTimeZone',
        'DateInterval',
    ];

    /**
     * @param list<string> $prefixes
     */
    public function __construct(private readonly array $prefixes = self::DEFAULT_PREFIXES) {}

    public static function fromConfiguration(mixed $extra): self
    {
        if (\is_string($extra)) {
            $extra = explode(',', $extra);
        }
        $prefixes = self::DEFAULT_PREFIXES;
        foreach (\is_array($extra) ? $extra : [] as $prefix) {
            if (\is_string($prefix) && '' !== trim($prefix)) {
                $prefixes[] = ltrim(trim($prefix), '\\');
            }
        }

        return new self($prefixes);
    }

    public static function fromGlobals(): self
    {
        return self::fromConfiguration($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['koh_async']['messageClassPrefixes'] ?? []);
    }

    public function allows(string $class): bool
    {
        foreach ($this->prefixes as $prefix) {
            if (str_ends_with($prefix, '\\') ? str_starts_with($class, $prefix) : $class === $prefix) {
                return true;
            }
        }

        return false;
    }

    /**
     * The classes in a serialized payload that are not on the list.
     *
     * Read without instantiating anything: a first pass with every class
     * refused turns each object into a __PHP_Incomplete_Class that remembers
     * only the name, so no constructor, __wakeup or __unserialize runs before
     * the names have been checked.
     *
     * @return list<string>
     */
    public function refused(string $serialized): array
    {
        $probe = @unserialize($serialized, ['allowed_classes' => false]);
        $refused = [];
        $this->collect($probe, $refused);

        return array_values(array_unique($refused));
    }

    /**
     * @param list<string> $refused
     */
    private function collect(mixed $value, array &$refused): void
    {
        if ($value instanceof \__PHP_Incomplete_Class) {
            $value = (array) $value;
            $class = $value['__PHP_Incomplete_Class_Name'] ?? '';
            if (\is_string($class) && !$this->allows($class)) {
                $refused[] = $class;
            }
            unset($value['__PHP_Incomplete_Class_Name']);
        }
        if (!\is_array($value)) {
            return;
        }
        foreach ($value as $item) {
            $this->collect($item, $refused);
        }
    }
}

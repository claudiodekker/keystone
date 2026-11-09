<?php

namespace ClaudioDekker\Keystone\Lint\Checks;

use ClaudioDekker\LaravelGuardrails\Check;
use ClaudioDekker\LaravelGuardrails\File;
use ClaudioDekker\LaravelGuardrails\Kind;
use ClaudioDekker\LaravelGuardrails\Violation;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Notification;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;

class MailQueuedAndEncrypted extends Check
{
    /**
     * The classes whose subclasses send mail.
     *
     * @var list<string>
     */
    protected const array MAIL = [Mailable::class, Notification::class];

    /**
     * Get the one-line description of the rule, shown when the checks are chosen.
     */
    public function description(): string
    {
        return 'Every mail and notification the packages send is queued and encrypted on the queue.';
    }

    /**
     * Get the kinds of code the check reads.
     *
     * @return list<Kind>
     */
    public function kinds(): array
    {
        return [Kind::SOURCE];
    }

    /**
     * Get the one-line description of the files the check reads.
     */
    public function scope(): string
    {
        return 'the source code of the packages';
    }

    /**
     * Get the failure message, naming the fix.
     */
    public function message(): string
    {
        return 'Implement ShouldQueue and ShouldBeEncrypted, so the queue never holds an address, IP address or user agent in clear.';
    }

    /**
     * Determine if the file is a package's source code.
     */
    protected function reads(File $file): bool
    {
        return parent::reads($file) && str_starts_with($file->path, 'packages/');
    }

    /**
     * Get a violation at each mail or notification class that isn't both queued and encrypted.
     *
     * @return list<Violation>
     */
    protected function violations(File $file): array
    {
        $classes = array_filter($file->findInstanceOf(Class_::class), $this->isUnencryptedMail(...));

        return array_map(fn (Class_ $class) => Violation::at($class->name ?? $class), array_values($classes));
    }

    /**
     * Determine if the class sends mail without being both queued and encrypted.
     */
    private function isUnencryptedMail(Class_ $class): bool
    {
        if ($class->extends === null || ! in_array($class->extends->toString(), static::MAIL, true)) {
            return false;
        }

        $interfaces = array_map(fn (Name $name) => $name->toString(), $class->implements);

        return ! in_array(ShouldQueue::class, $interfaces, true)
            || ! in_array(ShouldBeEncrypted::class, $interfaces, true);
    }
}

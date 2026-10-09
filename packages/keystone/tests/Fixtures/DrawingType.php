<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures;

use ClaudioDekker\Keystone\Methods\PresentsCeremony;

/**
 * A form type whose enrollment form shows a drawing of its code, far larger than a session cookie holds.
 */
class DrawingType extends FormType implements PresentsCeremony
{
    /**
     * Create a new drawing type instance.
     */
    public function __construct()
    {
        parent::__construct(name: 'drawn', surfaces: ['challenge', 'enrollment']);
    }

    /**
     * Add a drawing of the code to the page, made again on every visit.
     */
    public function present(array $page): array
    {
        return [...$page, 'drawing' => str_repeat($page['code'], 1024)];
    }
}

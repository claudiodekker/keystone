<?php

namespace ClaudioDekker\Keystone\Http\PageValues;

/**
 * @api
 */
readonly class EmailedLinkPage
{
    /**
     * Create a new emailed link page instance.
     *
     * @param  string  $action  the URL the page's one button posts to, carrying the link
     */
    public function __construct(
        #[\SensitiveParameter] public string $action,
    ) {
        //
    }
}

<?php

namespace ClaudioDekker\Keystone\Methods;

/**
 * How a page starts a credential type; the page renders one component per shape.
 *
 * @internal
 */
enum InitiateShape: string
{
    case CLIENT_CEREMONY = 'clientCeremony';
    case DELIVERED = 'delivered';
    case FORM = 'form';
    case REDIRECT = 'redirect';
}

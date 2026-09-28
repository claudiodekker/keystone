<?php

namespace ClaudioDekker\Keystone\Methods;

/**
 * @internal
 */
enum InitiateShape: string
{
    case FORM = 'form';
    case CLIENT_CEREMONY = 'clientCeremony';
    case REDIRECT = 'redirect';
    case DELIVERED = 'delivered';
}

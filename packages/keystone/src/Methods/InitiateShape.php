<?php

namespace ClaudioDekker\Keystone\Methods;

/**
 * @internal
 */
enum InitiateShape: string
{
    case CLIENT_CEREMONY = 'clientCeremony';
    case DELIVERED = 'delivered';
    case FORM = 'form';
    case REDIRECT = 'redirect';
}

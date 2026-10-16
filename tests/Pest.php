<?php

use ClaudioDekker\Keystone\Tests\TestCase;

pest()->extend(TestCase::class)->in(__DIR__, '../packages/*/tests');

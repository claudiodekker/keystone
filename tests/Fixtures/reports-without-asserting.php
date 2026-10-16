<?php

it('reports without asserting', function () {
    report(new RuntimeException('Alert failed to send.'));
});

<?php

declare(strict_types=1);

it('answers the health check', function (): void {
    $this->get('/up')->assertOk();
});

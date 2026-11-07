<?php

declare(strict_types=1);

arch('no debugging or deprecated PHP functions')->preset()->php();

arch('no functions with a known security risk')->preset()->security();

# MakerBot Replicator PHP SDK
Provides a wrapper class for communicating with MakerBot Replicator printers through JSON-RPC.

```php
<?php

use Makerbot\Replicator;

$Replicator = new Replicator('192.168.1.100');

$Replicator->authenticate();
$Replicator->loadFilament();
sleep(5);
$Replicator->stopFilament();
```

## Requirements
PHP 7.1+. Other than that, this library has no external requirements.

## Installation
You can install this library via Composer.
```bash
$ composer require abdyfranco/makerbot
```

## License
The MIT License (MIT). Please see "LICENSE.md" File for more information.

<?php

arch('the package does not depend on the App namespace of a host application')
    ->expect('TeckFuture\P6Analyzer')
    ->not->toUse('App');

arch('the package does not depend on spatie/laravel-data')
    ->expect('TeckFuture\P6Analyzer')
    ->not->toUse('Spatie\LaravelData');

arch('DTOs are final classes')
    ->expect('TeckFuture\P6Analyzer\DTOs')
    ->toBeFinal();

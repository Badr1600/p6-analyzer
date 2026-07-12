<?php

arch('no App namespace usage')
    ->expect('TeckFuture\P6Analyzer')
    ->not->toUse('App');

arch('no Spatie Data imports')
    ->expect('TeckFuture\P6Analyzer')
    ->not->toUse('Spatie\LaravelData');

arch('DTOs are final classes')
    ->expect('TeckFuture\P6Analyzer\DTOs')
    ->toBeFinal();

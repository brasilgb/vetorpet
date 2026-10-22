<?php

test('public VetorPet pages install the shared widget for the VetorPet product', function () {
    config([
        'services.vetorchat.widget_key' => str_repeat('a', 48),
        'services.vetorchat.script_url' => 'https://vetorleads.example/widget.js',
    ]);

    $this->get('/privacidade')->assertOk()
        ->assertSee('data-widget-key="'.str_repeat('a', 48).'"', false)
        ->assertSee('data-product="vetorpet"', false);
});

test('VetorPet keeps the visitor widget off administrative pages', function () {
    config([
        'services.vetorchat.widget_key' => str_repeat('a', 48),
        'services.vetorchat.script_url' => 'https://vetorleads.example/widget.js',
    ]);

    $this->get('/login')->assertOk()->assertDontSee('data-widget-key=', false);
});

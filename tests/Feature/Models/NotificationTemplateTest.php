<?php

use App\Models\NotificationTemplate;

test('fills every placeholder, with or without spaces inside the braces', function () {
    $template = new NotificationTemplate(['content' => 'Hi {{ name }}, order {{order_id}} costs {{ total }}. Bye {{name}}.']);

    expect($template->render(['name' => 'Ada', 'order_id' => 42, 'total' => 9.5]))
        ->toBe('Hi Ada, order 42 costs 9.5. Bye Ada.');
});

test('inserts values as they are without rendering placeholders inside them', function () {
    $template = new NotificationTemplate(['content' => 'Hi {{ name }} and {{ friend }}']);

    expect($template->render(['name' => '{{ friend }}', 'friend' => 'Grace']))
        ->toBe('Hi {{ friend }} and Grace');
});

test('reports the variables that have no text or number value', function () {
    $template = new NotificationTemplate(['content' => '{{ a }} {{ b }} {{ c }} {{ d }} {{ e }}']);

    expect($template->missingVariables(['a' => 'x', 'b' => null, 'c' => ['x'], 'd' => true]))
        ->toBe(['b', 'c', 'd', 'e']);
});

test('refuses to render when a variable has no value', function () {
    $template = new NotificationTemplate(['content' => 'Hi {{ name }}']);

    $template->render([]);
})->throws(LogicException::class, 'The template variable [name] has no text or number value.');

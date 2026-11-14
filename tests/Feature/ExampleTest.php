<?php

test('sends visitors of the home page to the dashboard', function () {
    $response = $this->get(route('home'));

    $response->assertRedirect(route('dashboard'));
});

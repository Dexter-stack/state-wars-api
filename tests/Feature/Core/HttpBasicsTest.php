<?php

it('reports health and readiness', function () {
    $this->get('/health')->assertOk()->assertExactJson(['ok' => true]);
    $this->get('/ready')->assertOk()->assertExactJson(['ok' => true]);
});

it('shows a welcome page at the base URL, and JSON to a client that asks for it', function () {
    $page = $this->get('/')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($page->headers->get('Content-Type'))->toStartWith('text/html');
    $page->assertSee('State Wars')->assertSee('/health')->assertSee('localhost:5173')
        ->assertDontSee('APP_')->assertDontSee('Laravel')->assertDontSee('PHP'); // nothing about the configuration or the stack

    $this->getJson('/')->assertOk()->assertExactJson(['name' => 'State Wars API', 'ok' => true, 'api' => '/v1', 'health' => '/health']);
    $this->postJson('/')->assertStatus(404); // still only a page: nothing can be posted to it
});

it('answers unknown routes with the Node error shape', function () {
    $this->getJson('/v1/nope')->assertStatus(404)
        ->assertExactJson(['error' => ['code' => 'not_found', 'message' => 'Route not found.']]);
    $this->postJson('/health')->assertStatus(404); // wrong method is a 404 too, like Fastify
});

it('rejects invalid JSON with a 400', function () {
    $this->call('POST', '/v1/anything', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"a":')
        ->assertStatus(400)
        ->assertExactJson(['error' => ['code' => 'bad_request', 'message' => 'The request body is not valid JSON.']]);
});

it('sets request id and security headers', function () {
    $this->get('/health', ['X-Request-Id' => 'abc-123'])
        ->assertHeader('X-Request-Id', 'abc-123')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
});

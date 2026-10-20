<?php

test('public information pages are available and link to all notices in the footer', function () {
    $routes = [
        'about' => '心の庵について',
        'legal.terms' => '利用規約',
        'legal.privacy' => 'プライバシーポリシー',
        'legal.commercial-transactions' => '特定商取引法に基づく表示',
        'legal.refund-credit-policy' => '返金・クレジットポリシー',
    ];

    foreach ($routes as $route => $title) {
        $response = $this->get(route($route));

        $response->assertOk()
            ->assertSee($title)
            ->assertSee('class="site-footer-links"', false);

        foreach ($routes as $footerRoute => $footerTitle) {
            $response->assertSee(route($footerRoute))
                ->assertSee($footerTitle);
        }

        $response->assertSeeInOrder([
            route('about'),
            '心の庵について',
            route('legal.terms'),
        ]);
    }
});

test('the about page and footer link use the selected locale', function () {
    $this->get(route('about', ['locale' => 'en']))
        ->assertOk()
        ->assertSee('About Kokoro no Iori')
        ->assertSee('A space to reflect')
        ->assertSeeInOrder([
            route('about', ['locale' => 'en']),
            'About Kokoro no Iori',
            route('legal.terms', ['locale' => 'en']),
            'Terms of service',
        ]);
});

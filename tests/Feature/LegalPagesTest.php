<?php

test('legal pages are public and link to all legal notices in the footer', function () {
    $routes = [
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
    }
});

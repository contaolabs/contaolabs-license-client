<?php

declare(strict_types=1);

namespace ContaoLabs\LicenseClientBundle\Tests;

use ContaoLabs\LicenseClientBundle\License\ProductRegistry;
use PHPUnit\Framework\TestCase;

class ProductRegistryTest extends TestCase
{
    public function testShortAndLongRegistration(): void
    {
        $registry = new ProductRegistry([
            'isotope-rent-products' => [
                'name' => 'Isotope Rent Products',
                'backend_modules' => ['cl_isotope_rent_bookings', 'cl_isotope_rent_blocks'],
                'module_types' => ['cl_isotope_rent_booking'],
                'content_types' => ['cl_isotope_rent_booking_ce', '', 42],
            ],
            'isotope-onepage-checkout' => 'Isotope OnePage-Checkout',
            'Ungültig!' => 'x',
        ]);

        $this->assertSame(['isotope-onepage-checkout' => 'Isotope OnePage-Checkout', 'isotope-rent-products' => 'Isotope Rent Products'], $registry->all());
        $this->assertSame(['cl_isotope_rent_booking_ce'], $registry->configs()['isotope-rent-products']['content_types']);
        $this->assertSame([], $registry->configs()['isotope-onepage-checkout']['module_types']);
        $this->assertSame('isotope-rent-products', $registry->productFor('backend_modules', 'cl_isotope_rent_blocks'));
        $this->assertSame('isotope-rent-products', $registry->productFor('module_types', 'cl_isotope_rent_booking'));
        $this->assertNull($registry->productFor('backend_modules', 'settings'));
        $this->assertSame('contaolabs_license_isotope_rent_products', ProductRegistry::field('isotope-rent-products'));
        $this->assertSame('isotope-rent-products', $registry->productForField('contaolabs_license_isotope_rent_products'));
    }
}

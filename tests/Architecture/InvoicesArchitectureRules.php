<?php

declare(strict_types=1);

namespace Tests\Architecture;

use DomainException;
use PHPat\Selector\Selector;
use PHPat\Test\Builder\BuildStep;
use PHPat\Test\PHPat;

/**
 * Structural rules and the layer and module map of the Invoices module, run by
 * PHPStan through phpat (a `phpat.test` service in phpstan.neon), not by
 * PHPUnit. The map rules forbid edges rather than list the allowed ones: no
 * inner layer reaches Infrastructure or Presentation, only Application and
 * Infrastructure reach Notifications (through its Api), Notifications does not
 * reach Invoices, only the Domain names the factories, and app/ stays apart
 * from the modules and the factories. Unchecked: the edges inside
 * Notifications (the task's own code), the factories' edges into the modules,
 * and routes.php, which is not a class.
 */
final class InvoicesArchitectureRules
{
    public function test_only_infrastructure_depends_on_infrastructure(): BuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Modules\Invoices'))
            ->excluding(Selector::inNamespace('Modules\Invoices\Infrastructure'))
            ->shouldNot()->dependOn()->classes(Selector::inNamespace('Modules\Invoices\Infrastructure'))
            ->because('Infrastructure wires the module; the inner layers do not know it');
    }

    public function test_only_presentation_depends_on_presentation(): BuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Modules\Invoices'))
            ->excluding(Selector::inNamespace('Modules\Invoices\Presentation'))
            ->shouldNot()->dependOn()->classes(Selector::inNamespace('Modules\Invoices\Presentation'))
            ->because('HTTP is the outermost layer');
    }

    public function test_invoices_reach_notifications_only_through_its_api(): BuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Modules\Invoices'))
            ->excluding(Selector::inNamespace('Modules\Invoices\Presentation'))
            ->shouldNot()->dependOn()->classes(Selector::inNamespace('Modules\Notifications'))
            ->excluding(Selector::inNamespace('Modules\Notifications\Api'))
            ->because('Api is the contract the Notifications module offers');
    }

    public function test_presentation_does_not_depend_on_notifications(): BuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Modules\Invoices\Presentation'))
            ->shouldNot()->dependOn()->classes(Selector::inNamespace('Modules\Notifications'))
            ->because('the controller notifies through the send service, never directly');
    }

    public function test_notifications_do_not_depend_on_invoices(): BuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Modules\Notifications'))
            ->shouldNot()->dependOn()->classes(Selector::inNamespace('Modules\Invoices'))
            ->because('Invoices depends on Notifications, never the other way');
    }

    public function test_only_the_domain_depends_on_the_factories(): BuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Modules'), Selector::inNamespace('App'))
            ->excluding(Selector::inNamespace('Modules\Invoices\Domain'))
            ->shouldNot()->dependOn()->classes(Selector::inNamespace('Database\Factories'))
            ->because('factories are test support that bypasses the status transitions');
    }

    public function test_modules_do_not_depend_on_app(): BuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Modules'), Selector::inNamespace('Database\Factories'))
            ->shouldNot()->dependOn()->classes(Selector::inNamespace('App'))
            ->because('app/ holds framework glue the modules must not rely on');
    }

    public function test_app_does_not_depend_on_the_modules(): BuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App'))
            ->shouldNot()->dependOn()->classes(Selector::inNamespace('Modules'))
            ->because('app/ holds framework glue that names no module');
    }

    public function test_invoices_classes_are_final(): BuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Modules\Invoices'))
            ->excluding(Selector::isInterface())
            ->should()->beFinal()
            ->because('module classes are not extension points');
    }

    public function test_application_data_is_readonly(): BuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Modules\Invoices\Application\Data'))
            ->should()->beReadonly()
            ->because('input DTOs are immutable once validated');
    }

    public function test_application_services_expose_only_handle(): BuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Modules\Invoices\Application\Services'))
            ->should()->haveOnlyOnePublicMethodNamed('handle')
            ->because('each application service is one use case with a single handle method');
    }

    public function test_domain_exceptions_extend_domain_exception(): BuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Modules\Invoices\Domain\Exceptions'))
            ->should()->extend()->classes(Selector::classname(DomainException::class))
            ->because('domain rule violations are DomainExceptions');
    }

    /**
     * Factories come in through the `@use HasFactory<...>` PHPDoc. PHP's
     * built-in classes are admitted here rather than through phpat's
     * ignore_built_in_classes, which would also drop the relation the
     * domain-exception rule checks.
     */
    public function test_domain_depends_only_on_whitelist(): BuildStep
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Modules\Invoices\Domain'))
            ->canOnly()->dependOn()->classes(
                Selector::inNamespace('Modules\Invoices\Domain'),
                Selector::inNamespace('Illuminate\Database\Eloquent'),
                Selector::inNamespace('Illuminate\Contracts\Database\Eloquent'),
                Selector::inNamespace('Database\Factories'),
                Selector::isStandardClass(),
            )
            ->because('Eloquent models and casts are the domain; the rest of Illuminate belongs to outer layers');
    }
}

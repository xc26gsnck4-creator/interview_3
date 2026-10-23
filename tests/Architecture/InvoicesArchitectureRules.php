<?php

declare(strict_types=1);

namespace Tests\Architecture;

use DomainException;
use PHPat\Selector\Selector;
use PHPat\Test\Builder\BuildStep;
use PHPat\Test\PHPat;

/**
 * Structural rules for the Invoices module, run by PHPStan through phpat (a
 * `phpat.test` service in phpstan.neon), not by PHPUnit. Deptrac owns the
 * layer and module map (deptrac.yaml). The Notifications module is the task's
 * own code and stays unchecked.
 */
final class InvoicesArchitectureRules
{
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
                Selector::inNamespace('Database\Factories'),
                Selector::isStandardClass(),
            )
            ->because('Eloquent models are the domain; the rest of Illuminate belongs to outer layers');
    }
}

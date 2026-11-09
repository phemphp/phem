<?php

/*
 * This file is part of the Phem package.
 *
 * (c) Yonel Ceruto <open@yceruto.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Phem\Form\TouchedFieldsExtension;
use Phem\Form\TouchedFieldsRequestHandler;
use Phem\Form\TouchedForms;
use Symfony\Component\DependencyInjection\ContainerInterface;

// The live validation of a Symfony form: an event of a form shows the errors of the touched
// fields only. With no framework.form, FrameworkBundle has no request handler, and the form
// extensions are not used.
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('phem.form.touched_forms', TouchedForms::class)
        ->tag('kernel.reset', ['method' => 'reset']);

    $services->set('phem.form.request_handler', TouchedFieldsRequestHandler::class)
        ->decorate('form.type_extension.form.request_handler', null, 0, ContainerInterface::IGNORE_ON_INVALID_REFERENCE)
        ->args([service('.inner'), service('phem.form.touched_forms')]);

    $services->set('phem.form.type_extension.touched_fields', TouchedFieldsExtension::class)
        ->args([service('phem.form.touched_forms')])
        ->tag('form.type_extension');
};

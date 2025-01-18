<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Form\Extension\Core\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @author Silas Joisten <silasjoisten@proton.me>
 * @author Patrick Reimers <preimers@pm.me>
 * @author Jules Pietri <jules@heahprod.com>
 */
final class MultiStepType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefault('current_step_name', static function (Options $options): string {
                return array_key_first($options['steps']);
            })
            ->setRequired('steps');
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $currentStep = $options['steps'][$options['current_step_name']];

        if (\is_callable($currentStep)) {
            $currentStep($builder, $options);
        } elseif (\is_string($currentStep)) {
            if (!class_exists($currentStep) || !is_a($currentStep, AbstractType::class)) {
                throw new \InvalidArgumentException(\sprintf('The form class "%s" does not exist.', $currentStep));
            }

            $builder->add($options['current_step_name'], $currentStep, $options);
        }
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['current_step_name'] = $options['current_step_name'];
        $view->vars['steps_names'] = array_keys($options['steps']);
    }
}

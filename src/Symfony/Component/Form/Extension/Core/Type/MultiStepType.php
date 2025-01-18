<?php

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
            ->setRequired('steps')
            ->setDefault('current_step', static function (Options $options): string {
                if (!is_string($first = \array_key_first($options['steps']))) {
                    throw new \InvalidArgumentException('The option "steps" must be an associative array.');
                }

                return $first;
            });
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $currentStep = $options['steps'][$options['current_step']];

        if (\is_callable($currentStep)) {
            $currentStep($builder, $options);
        } elseif (\is_string($currentStep)) {
            if (!class_exists($currentStep)) {
                throw new \InvalidArgumentException(\sprintf('The form class "%s" does not exist.', $currentStep));
            }

            if (!is_subclass_of($currentStep, AbstractType::class)) {
                throw new \InvalidArgumentException(\sprintf('"%s" is not a form type.', $currentStep));
            }

            $builder->add($options['current_step'], $currentStep);
        }
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['current_step'] = $options['current_step'];
        $view->vars['steps'] = array_keys($options['steps']);
    }
}

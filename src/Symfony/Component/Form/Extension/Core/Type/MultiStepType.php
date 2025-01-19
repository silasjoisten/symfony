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
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
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
            ->setAllowedTypes('steps', 'array')
            ->setAllowedValues('steps', static function (array $steps): bool {
                foreach ($steps as $key => $step) {
                    if (!\is_string($key)) {
                        return false;
                    }

                    if ((!\is_string($step) || !\is_subclass_of($step, AbstractType::class)) && !\is_callable($step)) {
                        return false;
                    }
                }

                return true;
            })
            ->setRequired('current_step')
            ->setAllowedTypes('current_step', 'string')
            ->setNormalizer('current_step', static function (Options $options, string $value): string {
                if (!\array_key_exists($value, $options['steps'])) {
                    throw new InvalidOptionsException(\sprintf('The current step "%s" does not exist.', $value));
                }

                return $value;
            })
            ->setDefault('current_step', static function (Options $options): string {
                return \array_key_first($options['steps']);
            });
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $currentStep = $options['steps'][$options['current_step']];

        if (\is_callable($currentStep)) {
            $currentStep($builder, $options);
        } elseif (\is_string($currentStep)) {
            $builder->add($options['current_step'], $currentStep);
        }
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['current_step'] = $options['current_step'];
        $view->vars['steps'] = \array_keys($options['steps']);
        $view->vars['total_steps_count'] = \count($options['steps']);

        /** @var int $currentStepIndex */
        $currentStepIndex = \array_search($options['current_step'], \array_keys($options['steps']), true);
        $view->vars['current_step_number'] = $currentStepIndex + 1;
        $view->vars['is_first_step'] = $currentStepIndex === 0;

        /** @var int $lastStepIndex */
        $lastStepIndex = \array_key_last(\array_keys($options['steps']));
        $view->vars['is_last_step'] = $lastStepIndex === $currentStepIndex;
    }
}

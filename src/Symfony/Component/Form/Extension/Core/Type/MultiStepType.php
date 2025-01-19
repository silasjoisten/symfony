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

                    if ((!\is_string($step) || !is_subclass_of($step, AbstractType::class)) && !\is_callable($step)) {
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
                /** @var string $firstStep */
                $firstStep = array_key_first($options['steps']);

                return $firstStep;
            })
            ->setRequired('next_step')
            ->setAllowedTypes('next_step', ['string', 'null'])
            ->setDefault('next_step', function (Options $options): ?string {
                return array_keys($options['steps'])[$this->currentStepIndex($options['current_step'], $options['steps']) + 1] ?? null;
            })
            ->setNormalizer('next_step', static function (Options $options, ?string $value): ?string {
                if (null === $value) {
                    return null;
                }

                if (!\array_key_exists($value, $options['steps'])) {
                    throw new InvalidOptionsException(\sprintf('The next step "%s" does not exist.', $value));
                }

                return $value;
            })
            ->setRequired('previous_step')
            ->setAllowedTypes('previous_step', ['string', 'null'])
            ->setDefault('previous_step', function (Options $options): ?string {
                return array_keys($options['steps'])[$this->currentStepIndex($options['current_step'], $options['steps']) - 1] ?? null;
            })
            ->setNormalizer('previous_step', static function (Options $options, ?string $value): ?string {
                if (null === $value) {
                    return null;
                }

                if (!\array_key_exists($value, $options['steps'])) {
                    throw new InvalidOptionsException(\sprintf('The previous step "%s" does not exist.', $value));
                }

                return $value;
            });

        $resolver->setDefaults([
            'hide_back_button_on_first_step' => false,
            'button_back_options' => [
                'label' => 'Back',
            ],
            'button_next_options' => [
                'label' => 'Next',
            ],
            'button_submit_options' => [
                'label' => 'Finish',
            ],
        ]);

        $resolver->setAllowedTypes('hide_back_button_on_first_step', 'bool');
        $resolver->setAllowedTypes('button_back_options', 'array');
        $resolver->setAllowedTypes('button_submit_options', 'array');
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $currentStep = $options['steps'][$options['current_step']];

        if (\is_callable($currentStep)) {
            $currentStep($builder, $options);
        } elseif (\is_string($currentStep)) {
            $builder->add($options['current_step'], $currentStep);
        }

        $builder->add('back', SubmitType::class, [
            'disabled' => $this->isFirstStep($options['current_step'], $options['steps']),
            'validate' => false,
            ...$options['button_back_options'],
        ]);

        if ($this->isFirstStep($options['current_step'], $options['steps']) && true === $options['hide_back_button_on_first_step']) {
            $builder->remove('back');
        }

        $builder->add('submit', SubmitType::class, $this->isLastStep($options['current_step'], $options['steps']) ? $options['button_submit_options'] : $options['button_next_options']);
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['current_step'] = $options['current_step'];
        $view->vars['steps'] = array_keys($options['steps']);
        $view->vars['total_steps_count'] = \count($options['steps']);
        $view->vars['current_step_number'] = $this->currentStepIndex($options['current_step'], $options['steps']) + 1;
        $view->vars['is_first_step'] = $this->isFirstStep($options['current_step'], $options['steps']);
        $view->vars['is_last_step'] = $this->isLastStep($options['current_step'], $options['steps']);
        $view->vars['previous_step'] = $options['previous_step'];
        $view->vars['next_step'] = $options['next_step'];
    }

    /**
     * @param array<string, mixed> $steps
     */
    private function currentStepIndex(string $currentStep, array $steps): int
    {
        /** @var int $currentStep */
        $currentStep = array_search($currentStep, array_keys($steps), true);

        return $currentStep;
    }

    /**
     * @param array<string, mixed> $steps
     */
    private function isLastStep(string $currentStep, array $steps): bool
    {
        return array_key_last(array_keys($steps)) === $this->currentStepIndex($currentStep, $steps);
    }

    /**
     * @param array<string, mixed> $steps
     */
    private function isFirstStep(string $currentStep, array $steps): bool
    {
        return 0 === $this->currentStepIndex($currentStep, $steps);
    }
}

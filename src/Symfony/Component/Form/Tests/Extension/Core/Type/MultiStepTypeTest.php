<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Form\Tests\Extension\Core\Type;

use Symfony\Component\Form\Extension\Core\Type\MultiStepType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Form\Tests\Fixtures\AuthorType;
use Symfony\Component\OptionsResolver\Exception\MissingOptionsException;

/**
 * @author Silas Joisten <silasjoisten@proton.me>
 */
final class MultiStepTypeTest extends TypeTestCase
{
    public function testConfigureOptionsWithoutStepsThrowsException()
    {
        self::expectException(MissingOptionsException::class);

        $this->factory->create(MultiStepType::class);
    }

    public function testConfigureOptionsWithStepsSetsDefaultForCurrentStepName()
    {
        $form = $this->factory->create(MultiStepType::class, [], [
            'steps' => [
                'general' => static function (): void {},
                'contact' => static function (): void {},
                'newsletter' => static function (): void {},
            ],
        ]);

        self::assertSame('general', $form->createView()->vars['current_step_name']);
    }

    public function testBuildViewHasStepNames(): void
    {
        $form = $this->factory->create(MultiStepType::class, [], [
            'steps' => [
                'general' => static function (): void {},
                'contact' => static function (): void {},
                'newsletter' => static function (): void {},
            ],
        ]);

        self::assertSame(['general', 'contact', 'newsletter'], $form->createView()->vars['steps_names']);
    }

    public function testFormOnlyHasCurrentStepForm()
    {
        $form = $this->factory->create(MultiStepType::class, [], [
            'steps' => [
                'general' => static function (FormBuilderInterface $builder): void {
                    $builder
                        ->add('firstName', TextType::class)
                        ->add('lastName', TextType::class);
                },
                'contact' => static function (FormBuilderInterface $builder): void {
                    $builder
                        ->add('address', TextType::class)
                        ->add('city', TextType::class);
                },
                'newsletter' => static function (): void {},
            ],
        ]);

        self::assertArrayHasKey('firstName', $form->createView()->children);
        self::assertArrayHasKey('lastName', $form->createView()->children);
        self::assertArrayNotHasKey('address', $form->createView()->children);
        self::assertArrayNotHasKey('city', $form->createView()->children);
    }

    public function testFormStepCanBeClassString()
    {
        $form = $this->factory->create(MultiStepType::class, ['current_step_name' => 'author'], [
            'steps' => [
                'general' => static function (FormBuilderInterface $builder): void {
                    $builder
                        ->add('firstName', TextType::class)
                        ->add('lastName', TextType::class);
                },
                'contact' => static function (FormBuilderInterface $builder): void {
                    $builder
                        ->add('address', TextType::class)
                        ->add('city', TextType::class);
                },
                'author' => AuthorType::class,
            ],
        ]);

        self::assertArrayHasKey('author', $form->createView()->children);
    }

    public function testFormStepWithNormalStringWillThrowException()
    {
        self::expectException(\InvalidArgumentException::class);

        $this->factory->create(MultiStepType::class, ['current_step_name' => 'author'], [
            'steps' => [
                'general' => static function (FormBuilderInterface $builder): void {
                    $builder
                        ->add('firstName', TextType::class)
                        ->add('lastName', TextType::class);
                },
                'contact' => static function (FormBuilderInterface $builder): void {
                    $builder
                        ->add('address', TextType::class)
                        ->add('city', TextType::class);
                },
                'author' => 'hello there',
            ],
        ]);
    }

    public function testFormStepWithClassStringNotExtendingAbstractTypeWillThrowException()
    {
        self::expectException(\InvalidArgumentException::class);

        $this->factory->create(MultiStepType::class, ['current_step_name' => 'author'], [
            'steps' => [
                'general' => static function (FormBuilderInterface $builder): void {
                    $builder
                        ->add('firstName', TextType::class)
                        ->add('lastName', TextType::class);
                },
                'contact' => static function (FormBuilderInterface $builder): void {
                    $builder
                        ->add('address', TextType::class)
                        ->add('city', TextType::class);
                },
                'author' => \stdClass::class,
            ],
        ]);
    }
}

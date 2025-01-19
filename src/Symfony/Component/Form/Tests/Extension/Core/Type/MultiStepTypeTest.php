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
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
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

    /**
     * @dataProvider invalidStepValues
     */
    public function testConfigureOptionsStepsMustBeArray(mixed $steps)
    {
        self::expectException(InvalidOptionsException::class);

        $this->factory->create(MultiStepType::class, [], ['steps' => $steps]);
    }

    /**
     * @return iterable<string, array<int, array<string, mixed>>>
     */
    public static function invalidStepValues(): iterable
    {
        yield 'Steps is string' => ['hello there'];
        yield 'Steps is int' => [3];
        yield 'Steps is null' => [null];
    }

    /**
     * @dataProvider invalidSteps
     *
     * @param array<string, mixed> $steps
     */
    public function testConfigureOptionsMustBeClassStringOrCallable(array $steps)
    {
        self::expectException(InvalidOptionsException::class);
        self::expectExceptionMessage('The option "steps" with value array is invalid.');

        $this->factory->create(MultiStepType::class, [], ['steps' => $steps]);
    }

    /**
     * @return iterable<string, array<int, array<string, mixed>>>
     */
    public static function invalidSteps(): iterable
    {
        yield 'Steps with invalid string value' => [['step1' => static function (): void {}, 'step2' => 'hello there']];
        yield 'Steps with invalid class value' => [['step1' => static function (): void {}, 'step2' => \stdClass::class]];
        yield 'Steps with array value' => [['step1' => static function (): void {}, 'step2' => []]];
        yield 'Steps with null value' => [['step1' => null]];
        yield 'Steps with int value' => [['step1' => 4]];
        yield 'Steps as non associative array' => [[0 => static function (): void {}]];
    }

    /**
     * @dataProvider invalidStepNames
     */
    public function testConfigureOptionsStepNameMustBeString(mixed $steps)
    {
        self::expectException(InvalidOptionsException::class);

        $this->factory->create(MultiStepType::class, [], ['steps' => ['step1' => static function (): void {}], 'current_step' => $steps]);
    }

    /**
     * @return iterable<string, array<int, array<string, mixed>>>
     */
    public static function invalidStepNames(): iterable
    {
        yield 'Step name is int' => [3];
        yield 'Step name is bool' => [false];
        yield 'Step name is callable' => [static function (): void {}];
    }

    public function testConfigureOptionsStepNameMustExistInSteps()
    {
        self::expectException(InvalidOptionsException::class);
        self::expectExceptionMessage('The current step "step2" does not exist.');

        $this->factory->create(MultiStepType::class, [], ['steps' => ['step1' => static function (): void {}], 'current_step' => 'step2']);
    }

    public function testConfigureOptionsSetsDefaultValueForCurrentStepName()
    {
        $form = $this->factory->create(MultiStepType::class, [], [
            'steps' => [
                'step1' => static function (): void {},
                'step2' => static function (): void {},
                'step3' => static function (): void {},
            ],
        ]);

        self::assertSame('step1', $form->createView()->vars['current_step']);
    }

    public function testBuildFormStepCanBeCallable()
    {
        $form = $this->factory->create(MultiStepType::class, [], [
            'current_step' => 'contact',
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
            ],
        ]);

        self::assertArrayHasKey('address', $form->createView()->children);
        self::assertArrayHasKey('city', $form->createView()->children);
    }

    public function testBuildFormStepCanBeClassString()
    {
        $form = $this->factory->create(MultiStepType::class, [], [
            'current_step' => 'author',
            'steps' => [
                'general' => static function (FormBuilderInterface $builder): void {
                    $builder
                        ->add('firstName', TextType::class)
                        ->add('lastName', TextType::class);
                },
                'author' => AuthorType::class,
            ],
        ]);

        self::assertArrayHasKey('author', $form->createView()->children);
    }

    public function testBuildView()
    {
        $form = $this->factory->create(MultiStepType::class, [], [
            'current_step' => 'contact',
            'steps' => [
                'contact' => static function (): void {},
                'general' => static function (): void {},
                'newsletter' => static function (): void {},
            ],
        ]);

        self::assertSame('contact', $form->createView()->vars['current_step']);
        self::assertSame(['contact', 'general', 'newsletter'], $form->createView()->vars['steps']);
        self::assertSame(3, $form->createView()->vars['total_steps_count']);
        self::assertSame(1, $form->createView()->vars['current_step_number']);
        self::assertTrue($form->createView()->vars['is_first_step']);
        self::assertFalse($form->createView()->vars['is_last_step']);
    }

    public function testBuildViewIsLastStep()
    {
        $form = $this->factory->create(MultiStepType::class, [], [
            'current_step' => 'newsletter',
            'steps' => [
                'contact' => static function (): void {},
                'general' => static function (): void {},
                'newsletter' => static function (): void {},
            ],
        ]);

        self::assertSame('newsletter', $form->createView()->vars['current_step']);
        self::assertSame(['contact', 'general', 'newsletter'], $form->createView()->vars['steps']);
        self::assertSame(3, $form->createView()->vars['total_steps_count']);
        self::assertSame(3, $form->createView()->vars['current_step_number']);
        self::assertFalse($form->createView()->vars['is_first_step']);
        self::assertTrue($form->createView()->vars['is_last_step']);
    }

    public function testBuildViewStepIsNotLastAndNotFirst()
    {
        $form = $this->factory->create(MultiStepType::class, [], [
            'current_step' => 'general',
            'steps' => [
                'contact' => static function (): void {},
                'general' => static function (): void {},
                'newsletter' => static function (): void {},
            ],
        ]);

        self::assertSame('general', $form->createView()->vars['current_step']);
        self::assertSame(['contact', 'general', 'newsletter'], $form->createView()->vars['steps']);
        self::assertSame(3, $form->createView()->vars['total_steps_count']);
        self::assertSame(2, $form->createView()->vars['current_step_number']);
        self::assertFalse($form->createView()->vars['is_first_step']);
        self::assertFalse($form->createView()->vars['is_last_step']);
    }
}

<?php

namespace App\Form;

use App\Entity\Employee;
use App\Entity\WorkSchedule;
use App\Repository\EmployeeRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class WorkScheduleType extends AbstractType
{
    public const DAY_LABELS = [
        1 => 'day.monday',
        2 => 'day.tuesday',
        3 => 'day.wednesday',
        4 => 'day.thursday',
        5 => 'day.friday',
        6 => 'day.saturday',
        7 => 'day.sunday',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'form.name',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 100)],
            ])
            ->add('startTime', TimeType::class, [
                'label' => 'form.start_time',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('endTime', TimeType::class, [
                'label' => 'form.end_time',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('toleranceMinutes', IntegerType::class, [
                'label' => 'form.tolerance_minutes',
                'constraints' => [new Assert\NotBlank(), new Assert\PositiveOrZero()],
            ])
            ->add('checkWindowMarginMinutes', IntegerType::class, [
                'label' => 'form.check_window_margin_minutes',
                'constraints' => [new Assert\NotBlank(), new Assert\PositiveOrZero()],
            ])
            ->add('employees', EntityType::class, [
                'class' => Employee::class,
                'choice_label' => 'fullName',
                'label' => 'form.employees',
                'multiple' => true,
                'expanded' => true,
                'mapped' => false,
                'required' => false,
                'query_builder' => fn (EmployeeRepository $repo): QueryBuilder => $repo->createQueryBuilder('e')
                    ->andWhere('e.isActive = true')
                    ->orderBy('e.lastName', 'ASC'),
            ]);

        foreach (self::DAY_LABELS as $dayOfWeek => $label) {
            $builder->add('day' . $dayOfWeek, FormType::class, [
                'data_class' => null,
                'mapped' => false,
                'label' => $label,
                'data' => $options['day_data'][$dayOfWeek] ?? null,
            ]);
            $builder->get('day' . $dayOfWeek)
                ->add('isRestDay', CheckboxType::class, [
                    'label' => 'form.rest_day',
                    'required' => false,
                ])
                ->add('startTime', TimeType::class, [
                    'label' => 'form.start_short',
                    'widget' => 'single_text',
                    'input' => 'datetime_immutable',
                    'required' => false,
                ])
                ->add('endTime', TimeType::class, [
                    'label' => 'form.end_short',
                    'widget' => 'single_text',
                    'input' => 'datetime_immutable',
                    'required' => false,
                ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => WorkSchedule::class,
            'day_data' => [],
        ]);
    }
}

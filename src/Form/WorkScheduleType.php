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
        1 => 'Lundi',
        2 => 'Mardi',
        3 => 'Mercredi',
        4 => 'Jeudi',
        5 => 'Vendredi',
        6 => 'Samedi',
        7 => 'Dimanche',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 100)],
            ])
            ->add('startTime', TimeType::class, [
                'label' => 'Heure de début',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('endTime', TimeType::class, [
                'label' => 'Heure de fin',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('toleranceMinutes', IntegerType::class, [
                'label' => 'Tolérance (minutes)',
                'constraints' => [new Assert\NotBlank(), new Assert\PositiveOrZero()],
            ])
            ->add('checkWindowMarginMinutes', IntegerType::class, [
                'label' => 'Marge de badgeage (minutes)',
                'constraints' => [new Assert\NotBlank(), new Assert\PositiveOrZero()],
            ])
            ->add('employees', EntityType::class, [
                'class' => Employee::class,
                'choice_label' => 'fullName',
                'label' => 'Employés',
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
                    'label' => 'Repos',
                    'required' => false,
                ])
                ->add('startTime', TimeType::class, [
                    'label' => 'Début',
                    'widget' => 'single_text',
                    'input' => 'datetime_immutable',
                    'required' => false,
                ])
                ->add('endTime', TimeType::class, [
                    'label' => 'Fin',
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

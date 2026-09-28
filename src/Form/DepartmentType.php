<?php

namespace App\Form;

use App\Entity\Department;
use App\Entity\Employee;
use App\Repository\EmployeeRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class DepartmentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'form.name',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 100)],
            ])
            ->add('isRemote', CheckboxType::class, [
                'label' => 'form.is_remote',
                'required' => false,
            ])
            ->add('breaks', CollectionType::class, [
                'entry_type' => DepartmentBreakType::class,
                'label' => 'form.breaks',
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'required' => false,
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
                    ->andWhere('e.isTrackingEnabled = true')
                    ->orderBy('e.lastName', 'ASC'),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Department::class]);
    }
}

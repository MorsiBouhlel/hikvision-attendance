<?php

namespace App\Form;

use App\Entity\Employee;
use App\Entity\User;
use App\Repository\EmployeeRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class UserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'form.email',
                'constraints' => [new Assert\NotBlank(), new Assert\Email()],
            ])
            ->add('role', ChoiceType::class, [
                'label' => 'form.role',
                'mapped' => false,
                'data' => $options['preselected_role'] ?? 'ROLE_VIEWER',
                'choices' => [
                    'role.admin' => 'ROLE_ADMIN',
                    'role.manager' => 'ROLE_MANAGER',
                    'role.viewer' => 'ROLE_VIEWER',
                    'role.employee' => 'ROLE_EMPLOYEE',
                ],
            ])
            ->add('employee', EntityType::class, [
                'class' => Employee::class,
                'choice_label' => 'fullName',
                'label' => 'form.linked_employee',
                'required' => false,
                'placeholder' => 'form.linked_employee_placeholder',
                'query_builder' => fn (EmployeeRepository $repo): QueryBuilder => $repo->createQueryBuilder('e')
                    ->andWhere('e.isActive = true')
                    ->orderBy('e.lastName', 'ASC'),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'preselected_role' => null,
        ]);
    }
}

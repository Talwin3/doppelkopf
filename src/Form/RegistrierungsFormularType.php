<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class RegistrierungsFormularType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('username', TextType::class, [
                'label' => 'Benutzername',
                'attr'  => ['placeholder' => 'Mindestens 3 Zeichen, nur Buchstaben, Zahlen, - und _'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'E-Mail-Adresse',
                'attr'  => ['placeholder' => 'deine@email.de'],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type'            => PasswordType::class,
                'mapped'          => false,
                'first_options'   => ['label' => 'Passwort', 'attr' => ['autocomplete' => 'new-password']],
                'second_options'  => ['label' => 'Passwort wiederholen'],
                'invalid_message' => 'Die Passwörter stimmen nicht überein.',
                'constraints'     => [
                    new NotBlank(message: 'Bitte ein Passwort eingeben.'),
                    new Length(
                        min: 8,
                        max: 4096,
                        minMessage: 'Das Passwort muss mindestens {{ limit }} Zeichen lang sein.',
                    ),
                ],
            ])
            ->add('datenschutzAkzeptiert', CheckboxType::class, [
                'mapped'      => false,
                'label'       => 'Ich habe die Datenschutzerklärung gelesen und akzeptiere sie.',
                'constraints' => [new IsTrue(message: 'Bitte akzeptiere die Datenschutzerklärung.')],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class]);
    }
}

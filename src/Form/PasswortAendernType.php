<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class PasswortAendernType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('aktuellesPasswort', PasswordType::class, [
                'label' => 'Aktuelles Passwort',
            ])
            ->add('neuesPasswort', RepeatedType::class, [
                'type'            => PasswordType::class,
                'first_options'   => ['label' => 'Neues Passwort'],
                'second_options'  => ['label' => 'Neues Passwort wiederholen'],
                'invalid_message' => 'Die Passwörter stimmen nicht überein.',
                'constraints'     => [
                    new Assert\NotBlank(message: 'Bitte ein neues Passwort eingeben.'),
                    new Assert\Length(min: 8, minMessage: 'Mindestens 8 Zeichen.'),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_protection' => true, 'csrf_field_name' => '_token']);
    }
}

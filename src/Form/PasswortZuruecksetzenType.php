<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Neues Passwort nach Klick auf den Reset-Link. Anders als
 * {@see PasswortAendernType} ohne Abfrage des alten Passworts – der Besitz des
 * Tokens ist hier der Nachweis.
 */
class PasswortZuruecksetzenType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('neuesPasswort', RepeatedType::class, [
                'type'            => PasswordType::class,
                'first_options'   => ['label' => 'Neues Passwort', 'attr' => ['autocomplete' => 'new-password']],
                'second_options'  => ['label' => 'Neues Passwort wiederholen', 'attr' => ['autocomplete' => 'new-password']],
                'invalid_message' => 'Die Passwörter stimmen nicht überein.',
                'constraints'     => [
                    new Assert\NotBlank(message: 'Bitte ein neues Passwort eingeben.'),
                    new Assert\Length(
                        min: 8,
                        minMessage: 'Das Passwort muss mindestens {{ limit }} Zeichen lang sein.',
                        max: 4096,
                    ),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_protection' => true, 'csrf_field_name' => '_token']);
    }
}

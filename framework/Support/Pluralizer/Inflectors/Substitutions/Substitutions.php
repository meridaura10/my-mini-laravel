<?php

namespace Framework\Kernel\Support\Pluralizer\Inflectors\Substitutions;

class Substitutions
{
    private $substitutions;

    public function __construct(Substitution ...$substitutions)
    {
        foreach ($substitutions as $substitution) {
            $this->substitutions[$substitution->getFrom()->word] = $substitution;
        }
    }

    public function getFlippedSubstitutions(): Substitutions
    {
        $substitutions = [];

        foreach ($this->substitutions as $substitution) {
            $substitutions[] = new Substitution(
                $substitution->getTo(),
                $substitution->getFrom()
            );
        }

        return new Substitutions(...$substitutions);
    }

    public function inflect(string $word): string
    {
        $lowerWord = strtolower($word);

        if (isset($this->substitutions[$lowerWord])) {
            $firstLetterUppercase = $lowerWord[0] !== $word[0];

            $toWord = $this->substitutions[$lowerWord]->getTo()->getWord();

            if ($firstLetterUppercase) {
                return strtoupper($toWord[0]) . substr($toWord, 1);
            }

            return $toWord;
        }

        return $word;
    }
}
<?php

declare(strict_types=1);

namespace App\People;

/**
 * The people of the Async search demos of Base UI. An app searches a database or an HTTP API: the
 * demo filters a fixed list, waits as a slow network, and fails for the text "will_error". The
 * assignment of a reviewer also waits, and fails for a person on leave.
 *
 * @phpstan-type Person array{id: string, name: string, username: string, email: string, title: string}
 */
final class PersonRepository
{
    private const array PEOPLE = [
        [
            'id' => 'leslie-alexander',
            'name' => 'Leslie Alexander',
            'username' => 'leslie',
            'email' => 'leslie.alexander@example.com',
            'title' => 'Product Manager',
        ],
        [
            'id' => 'kathryn-murphy',
            'name' => 'Kathryn Murphy',
            'username' => 'kathryn',
            'email' => 'kathryn.murphy@example.com',
            'title' => 'Marketing Lead',
        ],
        [
            'id' => 'courtney-henry',
            'name' => 'Courtney Henry',
            'username' => 'courtney',
            'email' => 'courtney.henry@example.com',
            'title' => 'Design Systems',
        ],
        [
            'id' => 'michael-foster',
            'name' => 'Michael Foster',
            'username' => 'michael',
            'email' => 'michael.foster@example.com',
            'title' => 'Engineering Manager',
        ],
        [
            'id' => 'lindsay-walton',
            'name' => 'Lindsay Walton',
            'username' => 'lindsay',
            'email' => 'lindsay.walton@example.com',
            'title' => 'Product Designer',
        ],
        [
            'id' => 'tom-cook',
            'name' => 'Tom Cook',
            'username' => 'tom',
            'email' => 'tom.cook@example.com',
            'title' => 'Frontend Engineer',
        ],
        [
            'id' => 'whitney-francis',
            'name' => 'Whitney Francis',
            'username' => 'whitney',
            'email' => 'whitney.francis@example.com',
            'title' => 'Customer Success',
        ],
        [
            'id' => 'jacob-jones',
            'name' => 'Jacob Jones',
            'username' => 'jacob',
            'email' => 'jacob.jones@example.com',
            'title' => 'Security Engineer',
        ],
        [
            'id' => 'arlene-mccoy',
            'name' => 'Arlene McCoy',
            'username' => 'arlene',
            'email' => 'arlene.mccoy@example.com',
            'title' => 'Data Analyst',
        ],
        [
            'id' => 'marvin-mckinney',
            'name' => 'Marvin McKinney',
            'username' => 'marvin',
            'email' => 'marvin.mckinney@example.com',
            'title' => 'QA Specialist',
        ],
        [
            'id' => 'eleanor-pena',
            'name' => 'Eleanor Pena',
            'username' => 'eleanor',
            'email' => 'eleanor.pena@example.com',
            'title' => 'Operations',
        ],
        [
            'id' => 'jerome-bell',
            'name' => 'Jerome Bell',
            'username' => 'jerome',
            'email' => 'jerome.bell@example.com',
            'title' => 'DevOps Engineer',
        ],
    ];

    /**
     * The people whose name, username, email or title has the text. No text gives no person.
     *
     * @return list<Person>
     */
    public function search(string $text): array
    {
        $text = mb_strtolower(trim($text));
        if ('' === $text) {
            return [];
        }

        usleep(400_000);

        if ('will_error' === $text) {
            throw new \RuntimeException('The directory of the people is not available.');
        }

        return array_values(array_filter(self::PEOPLE, static fn (array $person): bool => [] !== array_filter(
            [$person['name'], $person['username'], $person['email'], $person['title']],
            static fn (string $value): bool => str_contains(mb_strtolower($value), $text),
        )));
    }

    /** The people that cannot review: an assignment fails. */
    private const array ON_LEAVE = ['marvin-mckinney'];

    /**
     * Assigns the person as a reviewer.
     *
     * @return Person
     */
    public function assign(string $id): array
    {
        $person = $this->get($id);

        usleep(600_000);

        if (\in_array($id, self::ON_LEAVE, true)) {
            throw new \RuntimeException(\sprintf('%s is on leave and cannot review.', $person['name']));
        }

        return $person;
    }

    /** @return Person */
    public function get(string $id): array
    {
        foreach (self::PEOPLE as $person) {
            if ($person['id'] === $id) {
                return $person;
            }
        }

        throw new \OutOfBoundsException(\sprintf('No person has the id "%s".', $id));
    }
}

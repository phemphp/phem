import type { LanguageRegistration } from 'shiki';
import grammar from '../../../editors/vscode/syntaxes/phem.tmLanguage.json';

// The grammar of the editor plugins also colors the code of the site.
export const phem = { ...grammar, name: 'phem', embeddedLangs: ['php', 'css', 'javascript'] } as unknown as LanguageRegistration;

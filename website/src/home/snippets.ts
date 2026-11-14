// The code of the home page story, one snippet for each scene. The syntax follows the docs:
// LikeButton is the Counter of docs/introduction.md, the Article of the broadcast follows the
// LikeButton of docs/real-time.md, and the compiled output is the real output of `phemc build`.

// A mark gives a `data-mark` span to the n-th match of `text`: the story colors it and draws its
// lines from it.
export interface Mark {
    name: string;
    text: string;
    nth?: number;
}

export interface Snippet {
    code: string;
    lang: 'phem' | 'twig';
    title?: string;
    marks?: Mark[];
}

// Scenes 2 and 3: logic and markup in one file.
export const likeButton: Snippet = {
    title: 'src/Ui/Common/LikeButton.phem',
    lang: 'phem',
    code: `#[Component]
final class LikeButton
{
    #[State]
    public int $likes = 0;

    public function like(): void
    {
        ++$this->likes;
    }

    public function render(): Node
    {
        return (
            <button onClick={{ like }}>
                Like · {{ likes }}
            </button>
        );
    }
}`,
    marks: [
        { name: 'handler', text: 'like', nth: 1 },
        { name: 'update', text: '++$this->likes;' },
        { name: 'render', text: 'render' },
        { name: 'onclick', text: 'like', nth: 2 },
    ],
};

// Scene 4: components nest into a page, the page of the blog post of the illustration. Article
// gets the post, as in scene 6.
export const blogPost: Snippet = {
    title: 'src/Ui/BlogPostPage.phem',
    lang: 'phem',
    code: `#[Page('/posts/{id:post}')]
function BlogPostPage(Post $post): Node
{
    return (
        <Document title={{ post.title }}>
            <Article post={{ post }}>
                {{ post.content }}
                <LikeButton post={{ post }} />
            </Article>
        </Document>
    );
}`,
    marks: [
        { name: 'document', text: 'Document' },
        { name: 'article', text: 'Article' },
        { name: 'like-button', text: 'LikeButton' },
    ],
};

// Scene 5: the framework stays.
// A service comes in the constructor, or in a parameter of the handler, as in a controller action.
export const services: Snippet = {
    lang: 'phem',
    code: `public function __construct(
    private readonly PostRepository $posts,
) {
}

public function like(MessageBusInterface $bus): void`,
    marks: [
        { name: 'service', text: 'PostRepository' },
        { name: 'handler-service', text: 'MessageBusInterface' },
    ],
};

export const security: Snippet = {
    lang: 'phem',
    code: `#[IsGranted('ROLE_USER')]
public function like(): void`,
    marks: [{ name: 'security', text: 'IsGranted' }],
};

export const rateLimit: Snippet = {
    lang: 'phem',
    code: `#[RateLimit('likes')]
public function like(): void`,
    marks: [{ name: 'rate-limit', text: 'RateLimit' }],
};

// Scene 6: the LikeButton of the page (scene 4) saves the like with the repository of scene 5,
// and #[Broadcast] renders it again in the other browsers that show the same post. The likes are
// shared data.
export const broadcast: Snippet = {
    title: 'src/Ui/Common/LikeButton.phem',
    lang: 'phem',
    code: `#[Component]
final class LikeButton
{
    #[Prop, BroadcastKey] public Post $post;

    #[Broadcast]
    public function like(): void
    {
        ++$this->post->likes;
        $this->posts->save($this->post);
    }
}`,
    marks: [
        { name: 'handler', text: 'like' },
        { name: 'shared', text: '$this->post->likes' },
        { name: 'broadcast', text: 'Broadcast' },
    ],
};

// Scene 7: the real output of `phemc build src` for LikeButton. The PHP is one line in the
// output: here it wraps at the array.
export const compiledPhp: Snippet = {
    title: 'var/phem/php/Ui/Common/LikeButton.php',
    lang: 'phem',
    code: `new \\Phem\\Element('Ui/Common/LikeButton.0.twig', [
    'like' => $this->like(...),
    'likes' => $this->likes,
])`,
};

export const compiledTwig: Snippet = {
    title: 'var/phem/templates/Ui/Common/LikeButton.0.twig',
    lang: 'twig',
    code: `<button {{ phem_on('click', like) }}>
    Like · {{ likes }}
</button>`,
};

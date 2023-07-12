<?php
class YoutubeDataAPI {

    private array $youtube_ids = [];
    private string $youtube_api_key = ''; //API KEYを設定

    public function __construct() {
        $this->youtube_ids = $this->get_youtube_id();
        if (!$this->youtube_ids) {
            return;
        }
    }

    /**
     * 記事本文内からYoutubeの埋め込みコードを探索し、IDを取得
     * 
     * @return array $youtube_ids 見つかったYoutubeのID
     */
    private function get_youtube_id(): array {
        $content = file_get_contents("sample-source-code.txt");
        $patterns = [
            'https://www.youtube.com/embed/([0-9a-zA-Z-_]+)',
            'https://www.youtube.com/watch\?v=([0-9a-zA-Z-_]+)',
            'https://youtu.be/([0-9a-zA-Z-_]+)',
        ];
        $patterns = implode('|', $patterns);
        preg_match_all("#{$patterns}#", $content, $matches);

        if(empty($matches[0])) {
            return [];
        }

        $youtube_ids = [...$matches[1], ...$matches[2], ...$matches[3]];
        $youtube_ids = array_unique($youtube_ids);
        $youtube_ids = array_filter($youtube_ids, 'strlen');
        $youtube_ids = array_values($youtube_ids);

        return $youtube_ids;
    }

}
new YoutubeDataAPI();

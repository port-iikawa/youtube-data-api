<?php
class YoutubeDataAPI {

    private array $youtube_ids = [];
    private string $youtube_api_key = ''; //API KEYを設定

    public function __construct() {
        $this->youtube_ids = $this->get_youtube_id();
        if (!$this->youtube_ids) {
            return;
        }

        $schema = json_encode($this->generate_schema());
        echo "<script type='application/ld+json'>{$schema}</script>";
    }

    /**
     * 記事本文内からYoutubeの埋め込みコードを探索し、IDを取得
     * 
     * @return array $youtube_ids 見つかったYoutubeのID
     */
    private function get_youtube_id(): array {
        $content = file_get_contents("sample-source-code.txt");

        //3パターンのYoutubeのURLをマッチさせる過程で、同時にグループマッチングを利用して、ID部分をマッチさせる為の正規表現
        $patterns = [
            'https://www.youtube.com/embed/([0-9a-zA-Z-_]+)',
            'https://www.youtube.com/watch\?v=([0-9a-zA-Z-_]+)',
            'https://youtu.be/([0-9a-zA-Z-_]+)',
        ];

        //配列で用意した3パターンの正規表現を、|で区切ってOR検索の正規表現とする
        $patterns = implode('|', $patterns);

        //正規表現の実行
        //PHPの場合、正規表現のデリミタに「/」以外の文字列を利用出来る
        //今回のように、URLをマッチさせたい時に、/をエスケープしなくて良いので便利
        preg_match_all("#{$patterns}#", $content, $matches);

        //マッチするものが無かった場合は、空の配列をreturn
        if (empty($matches[0])) {
            return [];
        }

        //3パターンでマッチしたID達を1つの配列にまとめる
        //array_merge($matches[1], $matches[2], $matches[3])と同じ
        $youtube_ids = [...$matches[1], ...$matches[2], ...$matches[3]];
        //重複を削除
        $youtube_ids = array_unique($youtube_ids);
        //空文字列の要素を削除
        $youtube_ids = array_filter($youtube_ids, 'strlen');
        //配列のkeyを0から振り直す
        $youtube_ids = array_values($youtube_ids);

        //綺麗になった配列をreturn
        return $youtube_ids;
    }

    private function generate_schema() {
        $schema = [
            '@context' => 'https://schema.org',
        ];
        if (count($this->youtube_ids) === 1) {
            $schema = array_merge($schema, $this->generate_video_schema($this->youtube_ids[0]));
        } else {
            $schema['@type'] = 'itemList';
            $schema['itemListElement'] = [];
            foreach ($this->youtube_ids as $youtube_id) {
                $schema['itemListElement'][] = $this->generate_video_schema($youtube_id);
            }
        }
        return $schema;
    }

    /**
     * Youtube Data APIを利用して、動画の詳細情報を取得
     * 
     * @param string $youtube_id 詳細を取得する動画のID
     * @return array|false 動画の取得に成功した場合は情報を格納した配列、失敗した場合はfalse
     */
    private function generate_video_schema(string $youtube_id): array {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://www.googleapis.com/youtube/v3/videos?id={$youtube_id}&key={$this->youtube_api_key}"
                . "&part=snippet,contentDetails"
                . "&fields=items(contentDetails(duration),snippet(publishedAt,title,description,thumbnails))",
            CURLOPT_TIMEOUT => 1,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $result = json_decode(curl_exec($ch), true);

        if ($result === null || empty($result['items'])) {
            return [];
        }

        $video_info = $result['items'][0];

        $parse_video_info = [
            '@type' => 'VideoObject',
            'name' => $video_info['snippet']['title'],
            'duration' => $video_info['contentDetails']['duration'],
            'uploadDate' => $video_info['snippet']['publishedAt'],
            'thumbnailUrl' => $video_info['snippet']['thumbnails']['default']['url'],
            'description' => $video_info['snippet']['title'],
            'contentUrl' => $this->get_youtube_url_from_id($youtube_id),
            'regionsAllowed' => 'JPN',
            'hasPart' => $this->get_chapter_from_description(
                $youtube_id,
                $video_info['contentDetails']['duration'],
                $video_info['snippet']['description']
            ),
        ];

        return $parse_video_info;
    }

    /**
     * IDを基にYoutubeのURLを取得
     * 
     * @param string $youtube_id URLを取得する動画のID
     * @param array $queries: {
     *  'key1' => 'value1',
     *  'key2' => 'value2
     * } URLに付与する$_GETパラメータ
     */
    private function get_youtube_url_from_id(string $youtube_id, array $queries = []): string {
        $base_url = "https://www.youtube.com/watch?v={$youtube_id}";
        if($queries) {
            foreach($queries as $key => $val) {
                $base_url .= "&{$key}=" . urlencode($val);
            }
        }
        return $base_url;
    }

    /**
     * Youtubeの動画説明欄にチャプター用の目次が存在する場合、取得する
     * 
     * @param string $youtube_id URLを取得する動画のID
     * @param string $duration ISO 8601の形式で渡された終了時間
     * @param string $description 動画説明欄のテキスト
     */
    private function get_chapter_from_description(string $youtube_id, $duration, string $description): array {
        preg_match_all("/(([0-9]{1,2}:)?[0-9]{1,2}:[0-9]{1,2})(.+)/", $description, $matches);

        if (empty($matches[0])) {
            return [];
        }

        $chapter_start_times = $matches[1];
        $chapter_heading_texts = $matches[3];

        $chapters = [];
        foreach ($chapter_start_times as $key => $start_time) {
            $next_key = $key + 1;
            $start_seconds = $this->h_i_s_to_seconds($start_time);
            $end_time = $chapter_start_times[$next_key] ?? $this->iso8601_to_h_i_s($duration);
            $end_seconds = $this->h_i_s_to_seconds($end_time);
            $chapter_text = preg_replace("/(^\s+)|(\s+$)/u", "", $chapter_heading_texts[$key]);
            $chapter_url = $this->get_youtube_url_from_id($youtube_id, [
                't' => "{$start_seconds}s",
            ]);

            $chapters[] = [
                '@type' => 'Clip',
                'name' => $chapter_text,
                'startOffset' => $start_seconds,
                'endOffset' => $end_seconds,
                'url' => $chapter_url,
            ];
        }

        return $chapters;
    }

    /**
     * H:i:s形式の時間を秒数に変換
     * 
     * @param string $h_i_s H:i:s形式の文字列(i:sも可)
     */
    private function h_i_s_to_seconds(string $h_i_s): int {
        $h_i_s = explode(':', $h_i_s);

        if (count($h_i_s) === 3) {
            $hour = $h_i_s[0];
            $minute = $h_i_s[1];
            $second = $h_i_s[2];
        } else {
            $hour = 0;
            $minute = $h_i_s[0];
            $second = $h_i_s[1];
        }

        return (60 * 60 * $hour) + (60 * $minute) + $second;
    }

    /*
     * ISO8601を秒数に変換
     * @param string $duration ISO 8601(PT1H11M11S)の形式で渡された終了時間
     */
    private function iso8601_to_h_i_s(string $duration): string {
        preg_match_all('/PT(([0-9]){1,2}H)?(([0-9]{1,2})M)?([0-9]{1,2})S/', $duration, $matches,);
        $hour = $matches[2][0];
        $minute = $matches[4][0];
        $second = $matches[5][0];

        return sprintf("%02d:%02d:%02d", $hour, $minute, $second);
    }
}
new YoutubeDataAPI();

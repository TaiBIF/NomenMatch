# 要考慮 刪除 / 更新 的部分
# 直接在 NomenMatch server 上進行
#
# 修正紀錄:
#   1. import 指令加 -T,讓無 TTY 環境(nohup / cron)也能執行
#   2. subprocess 改用 run 並檢查 returncode,import/delete 失敗即 raise 中止
#   3. 順序維持「先刪舊的同 namecode → 再匯入新的」(delete 與 import 同一組 namecode)
#   4. get_deleted_taxon_ids 只認 api_taxon 本體刪除,避免誤刪(usage 刪除交給重新匯入反映)
#   5. main 尾端再排除本次已重新匯入的 taxon,作為保險

import pymysql
import pandas as pd
from datetime import datetime
import numpy as np
import os
import subprocess
import time
from tqdm import tqdm
from functools import lru_cache
from dotenv import load_dotenv

# 不帶參數，它會優先讀取系統的環境變數。
# 如果環境變數沒有，才會試著找當前目錄下的 .env（方便你本地端開發測試）
load_dotenv()

db_settings = {
    'host': os.getenv('DB_HOST'),
    'user': os.getenv('DB_USER'),
    'password': os.getenv('DB_PASSWORD'),
    'db': os.getenv('DB_NAME'),
    'charset': os.getenv('DB_CHARSET', 'utf8'),
}

# 載入變體字典並預處理
var_df = pd.read_csv('variants.csv')
char_replacement_dict = dict(zip(var_df['B'], var_df['A']))

SOLR_BATCH_SIZE = 100
IMPORT_BATCH_SIZE = 50


@lru_cache(maxsize=10000)
def replace_char_cached(string):
    """使用快取的字元替換函數"""
    if not string:
        return string
    for old_char, new_char in char_replacement_dict.items():
        string = string.replace(old_char, new_char)
    return string


def replace_char_vectorized(series):
    """向量化的字元替換"""
    result = series.copy()
    for old_char, new_char in char_replacement_dict.items():
        result = result.str.replace(old_char, new_char, regex=False)
    return result


bio_group_map = {
    '蝦蟹類': ['t0000516'],
    '昆蟲': ['t0000512'],
    '蛾類': {'include': ['t0001937'], 'exclude': ['t0123986']},
    '蝶類': ['t0123986'],
    '蜻蛉類': ['t0002023'],
    '甲蟲類': ['t0001780'],
    '蝸牛與貝類': ['t0000472', 't0000503', 't0000539', 't0000548'],
    '蜘蛛': ['t0001697'],
    '魚類': ['t0000203', 't0000204', 't0000522'],
    '兩棲類': ['t0000464'],
    '爬蟲類': ['t0000545'],
    '鳥類': ['t0000650', 't0002889'],
    '哺乳類': ['t0000517'],
    '維管束植物': ['t0000043'],
    '蕨類植物': ['t0000445', 't0000452'],
    '苔蘚植物': ['t0000090', 't0000091', 't0000095'],
    '藻類': ['t0000007', 't0000092', 't0000093', 't0000096'],
    '病毒': ["t0104550"],
    '細菌': ["t0000005"],
    '真菌': ["t0000008"],
}


def get_database_connection():
    """獲取資料庫連接"""
    return pymysql.connect(**db_settings)


def load_ranks():
    """載入階層資料"""
    with get_database_connection() as conn:
        query = "SELECT id, display, `order` FROM ranks"
        with conn.cursor() as cursor:
            cursor.execute(query)
            ranks = cursor.fetchall()
            return dict(zip([r[0] for r in ranks], [eval(r[1])['en-us'] for r in ranks]))


def get_updated_taxon_ids(cursor):
    """取得最近一次更新批次的 taxon_ids"""
    cursor.execute('''
        WITH base_query AS (SELECT MAX(updated_at) AS updated_at FROM api_taxon)
        SELECT taxon_id FROM api_taxon
        JOIN base_query ON base_query.updated_at = api_taxon.updated_at
        WHERE api_taxon.is_deleted = 0
        UNION ALL
        SELECT taxon_id FROM api_taxon_usages
        JOIN base_query ON base_query.updated_at = api_taxon_usages.updated_at
        WHERE api_taxon_usages.is_deleted = 0;
    ''')
    return list(set(t[0] for t in cursor.fetchall()))


def get_deleted_taxon_ids(cursor):
    """取得最近一次更新批次中本體已刪除的 taxon_ids"""
    cursor.execute('''
        WITH base_query AS (SELECT MAX(updated_at) AS updated_at FROM api_taxon)
        SELECT taxon_id FROM api_taxon
        JOIN base_query ON base_query.updated_at = api_taxon.updated_at
        WHERE api_taxon.is_deleted = 1;
    ''')
    return list(set(t[0] for t in cursor.fetchall()))


def load_main_data(cursor, taxon_ids):
    """載入主要分類資料（限定 taxon_ids）"""
    cursor.execute("""
        SELECT t.taxon_id, t.taxon_id, CONCAT_WS(' ', tn.name, an.name_author),
               t.taxon_id, t.taxon_id, t.rank_id, att.path, tn.name, atu.status,
               t.is_in_taiwan, att.parent_taxon_id
        FROM api_taxon_usages atu
        JOIN api_taxon t ON atu.taxon_id = t.taxon_id
        JOIN taxon_names tn ON atu.taxon_name_id = tn.id
        JOIN api_names an ON atu.taxon_name_id = an.taxon_name_id
        LEFT JOIN api_taxon_tree att ON atu.taxon_id = att.taxon_id
        WHERE t.taxon_id IN %s
    """, (taxon_ids,))

    columns = ['namecode', 'accepted_namecode', 'scientific_name', 'name_url_id',
               'accepted_url_id', 'rank', 'path', 'simple_name', 'name_status',
               'is_in_taiwan', 'parent_taxon_id']

    return pd.DataFrame(cursor.fetchall(), columns=columns)


def load_common_names(cursor, taxon_ids):
    """載入中文名資料（限定 taxon_ids）"""
    cursor.execute(
        "SELECT taxon_id, is_primary, name_c FROM api_common_name WHERE taxon_id IN %s",
        (taxon_ids,)
    )

    name_c_df = pd.DataFrame(cursor.fetchall(), columns=['taxon_id', 'is_primary', 'common_name_c'])

    name_c_df = (name_c_df
                 .sort_values(['taxon_id', 'is_primary'], ascending=[True, False])
                 .groupby('taxon_id', as_index=False)
                 .agg({'common_name_c': ','.join}))

    name_c_df = name_c_df.rename(columns={'taxon_id': 'namecode'})

    return name_c_df


def process_alternative_names(name_c_df):
    """處理替代名稱"""
    split_names = name_c_df['common_name_c'].str.split(',')
    name_c_df['common_name_c'] = split_names.str[0]
    name_c_df['alternative_name_c'] = split_names.apply(
        lambda x: ','.join(x[1:]) if len(x) > 1 else ''
    )

    name_c_df['new_alternative_name_c'] = ''
    mask = name_c_df['alternative_name_c'] != ''

    if mask.sum() > 0:
        name_c_df.loc[mask, 'new_common_name_c'] = replace_char_vectorized(
            name_c_df.loc[mask, 'common_name_c']
        )

        def process_alternatives(row):
            if row['alternative_name_c']:
                alternatives = row['alternative_name_c'].split(',')
                processed = [replace_char_cached(alt.strip()) for alt in alternatives]
                main_name = replace_char_cached(row['common_name_c'])
                processed = [alt for alt in processed if alt != main_name]
                return ','.join(list(set(processed)))
            return ''

        tqdm.pandas(desc="處理異體字")
        name_c_df.loc[mask, 'new_alternative_name_c'] = (
            name_c_df[mask].progress_apply(process_alternatives, axis=1)
        )

    return name_c_df


def load_path_data(cursor):
    """載入路徑資料"""
    cursor.execute("""
        SELECT t.rank_id, tn.name, t.taxon_id
        FROM api_taxon t
        JOIN taxon_names tn ON t.accepted_taxon_name_id = tn.id
        WHERE t.rank_id IN (3,12,18,22,26,30)
    """)

    return pd.DataFrame(cursor.fetchall(), columns=['rank', 'simple_name', 'taxon_id'])


def assign_bio_groups(df):
    """分配生物群組"""
    df_partial = df[['namecode', 'path']].drop_duplicates().copy()
    df_partial['bio_group'] = ''

    for bio_group, val in bio_group_map.items():
        if isinstance(val, list):
            for taxon_id in val:
                mask = df_partial['path'].str.contains(taxon_id, na=False)
                df_partial.loc[mask, 'bio_group'] = bio_group
        elif isinstance(val, dict):
            for taxon_id in val['include']:
                mask = df_partial['path'].str.contains(taxon_id, na=False)
                for ex in val.get('exclude', []):
                    mask = mask & ~df_partial['path'].str.contains(ex, na=False)
                df_partial.loc[mask, 'bio_group'] = bio_group

    return df.merge(df_partial[['namecode', 'bio_group']], how='left')


def process_higher_taxa_batch(df, path_df, rank_map):
    """批量處理高階分類（dict lookup）"""
    target_ranks = {3, 12, 18, 22, 26, 30}
    rank_columns = {rank: rank_map[rank].lower() for rank in target_ranks}

    for rank_col in rank_columns.values():
        df[rank_col] = ''

    has_path_mask = df['path'].notna() & (df['path'] != '')

    if not has_path_mask.any():
        return df

    # 建立 lookup dict 取代逐次 DataFrame 過濾
    path_lookup = {}
    for _, row in path_df.iterrows():
        path_lookup[row['taxon_id']] = (row['rank'], row['simple_name'])

    print("處理高階分類資料...")
    for idx in tqdm(df[has_path_mask].index):
        for taxon_id in df.loc[idx, 'path'].split('>'):
            if taxon_id in path_lookup:
                rank_id, name = path_lookup[taxon_id]
                if rank_id in target_ranks:
                    df.loc[idx, rank_columns[rank_id]] = name

    return df


def solr_batch_delete(taxon_ids, batch_size=SOLR_BATCH_SIZE):
    """批次從 Solr 刪除 taxon"""
    for i in range(0, len(taxon_ids), batch_size):
        batch = taxon_ids[i:i + batch_size]
        query = ' OR '.join([f'namecode:{tid}' for tid in batch])
        cmd = (
            f'curl -s http://solr:8983/solr/taxa/update/?commit=true '
            f'-H "Content-Type: text/xml" '
            f"--data-binary '<delete><query>{query}</query></delete>'"
        )
        result = subprocess.run(cmd, shell=True, capture_output=True, text=True)
        if result.returncode != 0:
            raise RuntimeError(
                f"solr_batch_delete 失敗 (returncode={result.returncode})，"
                f"stderr: {result.stderr[:500]}"
            )
        done = min(i + batch_size, len(taxon_ids))
        if done % 500 == 0 or done == len(taxon_ids):
            print(f"  已刪除 {done}/{len(taxon_ids)}")


def solr_batch_import(df, today_str):
    """分批更新 Solr:每批「先刪舊的同 namecode → 再匯入新的」"""
    unique_namecodes = list(df.namecode.unique())
    print(f"Solr 分批更新 {len(unique_namecodes)} 筆...")

    for i in range(0, len(unique_namecodes), IMPORT_BATCH_SIZE):
        batch_codes = unique_namecodes[i:i + IMPORT_BATCH_SIZE]
        batch_num = i // IMPORT_BATCH_SIZE + 1

        # 1. 先刪除這批的舊資料(delete 與 import 打同一組 namecode,故必須先刪後匯)
        query = ' OR '.join([f'namecode:{tid}' for tid in batch_codes])
        del_cmd = (
            f'curl -s http://solr:8983/solr/taxa/update/?commit=true '
            f'-H "Content-Type: text/xml" '
            f"--data-binary '<delete><query>{query}</query></delete>'"
        )
        del_result = subprocess.run(del_cmd, shell=True, capture_output=True, text=True)
        if del_result.returncode != 0:
            raise RuntimeError(
                f"批次 {batch_num} delete 失敗 (returncode={del_result.returncode})，"
                f"stderr: {del_result.stderr[:500]}"
            )

        # 2. 寫出這批 CSV 並匯入新資料
        batch_csv = f'./v2/source-data/source_taicol_{today_str}_{batch_num}.csv'
        df[df.namecode.isin(batch_codes)].to_csv(batch_csv, sep='\t', header=None, index=False)

        # -T:無 TTY 環境(nohup/cron)也能執行; log 不覆寫,方便日後查
        commands = (
            f"docker exec nomenmatch-php bash -c "
            f"'(cd /code/v2/workspace && php ./importChecklistToSolr.php "
            f"../source-data/source_taicol_{today_str}_{batch_num}.csv taicol_2 "
            f"&> /code/output_taicol_2_{batch_num})'"
        )
        result = subprocess.run(commands, shell=True, capture_output=True, text=True)
        if result.returncode != 0:
            os.remove(batch_csv)
            raise RuntimeError(
                f"批次 {batch_num} import 失敗 (returncode={result.returncode})，"
                f"該批舊資料已刪除但新資料未匯入,請檢查後重跑。"
                f"stderr: {result.stderr[:500]}"
            )

        os.remove(batch_csv)

        done = min(i + IMPORT_BATCH_SIZE, len(unique_namecodes))
        print(f"  匯入進度: {done}/{len(unique_namecodes)}")

        if done < len(unique_namecodes):
            time.sleep(1)


def main():
    """主要執行函數"""
    today = datetime.today()
    today_str = today.strftime("%Y%m%d")

    print("開始載入資料...")

    rank_map = load_ranks()

    with get_database_connection() as conn:
        with conn.cursor() as cursor:
            # 取得需更新的 taxon_ids
            updated_taxon_ids = get_updated_taxon_ids(cursor)
            print(f"需更新: {len(updated_taxon_ids)} 筆")

            # 無更新資料時,仍處理刪除後直接結束
            if not updated_taxon_ids:
                deleted_taxon_ids = get_deleted_taxon_ids(cursor)
                print(f"無更新資料,Solr 刪除 {len(deleted_taxon_ids)} 筆...")
                solr_batch_delete(deleted_taxon_ids)
                print(f"完成！共更新 0 筆,刪除 {len(deleted_taxon_ids)} 筆")
                return

            # 載入主要資料
            df = load_main_data(cursor, updated_taxon_ids)
            print(f"載入主要資料: {len(df)} 筆記錄")

            # 載入中文名稱
            name_c_df = load_common_names(cursor, updated_taxon_ids)

            # 載入路徑資料
            path_df = load_path_data(cursor)

    # 處理中文名異體字
    name_c_df = process_alternative_names(name_c_df)
    print(f"處理中文名稱: {len(name_c_df)} 筆記錄")

    # 合併資料
    df = df.merge(name_c_df, how='left')
    df = df.fillna('')
    df = df.drop_duplicates().reset_index(drop=True)

    # 分配生物群組
    df = assign_bio_groups(df)

    # 處理高階分類
    df = process_higher_taxa_batch(df, path_df, rank_map)

    # 轉換階層ID為名稱
    df['rank'] = df['rank'].map(rank_map)
    df = df.fillna('')

    # 重新排列欄位
    df = df[[
        'namecode', 'accepted_namecode', 'scientific_name', 'name_url_id',
        'accepted_url_id', 'common_name_c', 'rank', 'genus', 'family',
        'order', 'class', 'phylum', 'kingdom', 'simple_name', 'name_status',
        'new_alternative_name_c', 'bio_group', 'is_in_taiwan', 'parent_taxon_id'
    ]]

    df = df.replace({np.nan: None})

    # 輸出 CSV
    updating_csv = f'./v2/source-data/source_taicol_{today_str}.csv'
    df.to_csv(updating_csv, sep='\t', header=None, index=False)

    # 更新 sources.csv
    source = pd.read_table('./v2/source-data/sources.csv', sep='\t', header=None)
    source.loc[source[0] == 'taicol_2', 5] = today.strftime("%Y-%m-%d")
    source.to_csv('./v2/source-data/sources.csv', sep='\t', header=None, index=None)

    # Solr 分批更新
    solr_batch_import(df, today_str)

    # 處理已刪除的 taxon
    with get_database_connection() as conn:
        with conn.cursor() as cursor:
            deleted_taxon_ids = get_deleted_taxon_ids(cursor)

    # 排除本次已重新匯入的 taxon,避免有效 taxon 被誤刪(保險)
    deleted_taxon_ids = list(set(deleted_taxon_ids) - set(updated_taxon_ids))

    print(f"Solr 刪除 {len(deleted_taxon_ids)} 筆已刪除資料...")
    solr_batch_delete(deleted_taxon_ids)

    # 清除主 CSV
    os.remove(updating_csv)
    print(f"完成！共更新 {len(df.namecode.unique())} 筆，刪除 {len(deleted_taxon_ids)} 筆")


if __name__ == "__main__":
    main()
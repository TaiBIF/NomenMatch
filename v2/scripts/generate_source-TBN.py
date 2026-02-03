# 使用方式
# python3.7 ./v2/scripts/generate_source-TBN.py  -i [原始來源檔案路徑] -d [來源檔案下載日期]

import pandas as pd
import numpy as np
from datetime import date
import re
import argparse

# 命令列參數
parser = argparse.ArgumentParser(description='處理 TBN 物種資料')
parser.add_argument('--input', '-i', required=True, help='輸入 CSV 檔案路徑')
parser.add_argument('--date', '-d', required=True, help='版本日期 (YYYY-MM-DD)')
args = parser.parse_args()

# 從 date 取得 year
version_date = args.date
version_year = version_date.split('-')[0]
citation = f'TBN：台灣生物多樣性網絡（{version_year}）TBN首頁 https://www.tbn.org.tw/ 。瀏覽於 {version_date}。行政院農業委員會特有生物研究保育中心。'

df = pd.read_csv(args.input)
var_df = pd.read_csv('variants.csv')

df = df.replace({np.nan: None})

df['name_status'] = 'accepted'


def get_infraspecies_rank(row):
    if row['taxonRank'] != 'infraspecies':
        return row['taxonRank']
    rank_list = []
    if row['subspecies'] is not None:
        rank_list.append('subspecies')
    if row['variety'] is not None:
        rank_list.append('variety')
    if row['form'] is not None:
        rank_list.append('form')
    if row['cultigen'] is not None:
        rank_list.append('cultigen')
    return ','.join(rank_list) if rank_list else row['taxonRank']


mask = df['taxonRank'] == 'infraspecies'
if mask.any():
    df.loc[mask, 'taxonRank'] = df.loc[mask].apply(get_infraspecies_rank, axis=1)

char_map = dict(zip(var_df.B, var_df.A))


def replace_char(string):
    if string is None or pd.isna(string):
        return None
    for old, new in char_map.items():
        string = string.replace(old, new)
    return string


def is_chinese(text):
    return bool(re.search(r'[\u4e00-\u9fff]', text))


def extract_chinese_names(alt_names, common_name):
    if alt_names is None or pd.isna(alt_names):
        return None
    new_common_name = replace_char(common_name)
    names = [n.strip() for n in alt_names.split(';')]
    chinese_names = [n for n in names if n and is_chinese(n)]
    chinese_names = [replace_char(n) for n in chinese_names if replace_char(n) != new_common_name]
    chinese_names = list(set(chinese_names))
    return ','.join(chinese_names) if chinese_names else None


df['alternative_name_c'] = [
    extract_chinese_names(alt, common) 
    for alt, common in zip(df['alternativeName'], df['vernacularName'])
]

df = df[['taxonUUID','taxonUUID','scientificName','taxonUUID','taxonUUID','vernacularName','taxonRank',
'genus','family','order','class','phylum','kingdom','simplifiedScientificName','name_status','alternative_name_c']]

today = date.today()
today_str = today.strftime("%Y%m%d")

df = df.replace({np.nan: None})

df.to_csv(f'./v2/source-data/source_tbn_{today_str}.csv', sep='\t', header=None, index=False)

source = pd.read_table('./v2/source-data/sources.csv', sep='\t', header=None)
if not (source[0] == 'tbn').any():
    source = pd.concat([source, pd.DataFrame({0: ['tbn']})], ignore_index=True)

source.loc[source[0]=='tbn',1] = 'TBN'
source.loc[source[0]=='tbn',2] = 'https://www.tbn.org.tw/taxa/'
source.loc[source[0]=='tbn',3] = citation
source.loc[source[0]=='tbn',4] = 'https://www.tbn.org.tw/'
source.loc[source[0]=='tbn',5] = version_date

source.to_csv('./v2/source-data/sources.csv', sep='\t', header=None, index=None)
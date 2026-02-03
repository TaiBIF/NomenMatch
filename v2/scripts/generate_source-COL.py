# 使用方式
# python3.7 ./v2/scripts/generate_source-COL.py  -i [原始來源檔案路徑] -d [來源檔案下載日期]

import numpy as np
import pandas as pd
from datetime import date
import argparse

# 命令列參數
parser = argparse.ArgumentParser(description='處理 COL 物種資料')
parser.add_argument('--input', '-i', required=True, help='輸入 TSV 檔案路徑')
parser.add_argument('--date', '-d', required=True, help='版本日期 (YYYY-MM-DD)')
parser.add_argument('--citation', '-c', required=True, help='引用文字')
args = parser.parse_args()

today = date.today()
today_str = today.strftime("%Y%m%d")

df = pd.read_table(args.input, sep='\t', usecols=[
    'dwc:taxonID', 'dwc:acceptedNameUsageID', 'dwc:scientificName',
    'dwc:taxonRank', 'dwc:scientificNameAuthorship', 'dwc:taxonomicStatus',
    'dwc:kingdom', 'dwc:phylum', 'dwc:class', 'dwc:order', 'dwc:family', 'dwc:genus'
])

df = df.replace({np.nan: None})

# 處理 simple_name（向量化）
df['simple_name'] = df.apply(
    lambda x: x['dwc:scientificName'].replace(x['dwc:scientificNameAuthorship'], '').strip()
    if x['dwc:scientificName'] and x['dwc:scientificNameAuthorship']
    else (x['dwc:scientificName'].strip() if x['dwc:scientificName'] else None),
    axis=1
)

df['common_name_c'] = None

# 欄位順序
df = df[[
    'dwc:taxonID', 'dwc:acceptedNameUsageID', 'dwc:scientificName',
    'dwc:taxonID', 'dwc:acceptedNameUsageID', 'common_name_c', 'dwc:taxonRank',
    'dwc:genus', 'dwc:family', 'dwc:order', 'dwc:class', 'dwc:phylum', 'dwc:kingdom',
    'simple_name', 'dwc:taxonomicStatus'
]]

df = df.replace({np.nan: None})

df.to_csv(f'./v2/source-data/source_col_{today_str}.csv', sep='\t', header=None, index=False)

# 更新 sources.csv
source = pd.read_table('./v2/source-data/sources.csv', sep='\t', header=None)
if not (source[0] == 'col').any():
    source = pd.concat([source, pd.DataFrame({0: ['col']})], ignore_index=True)

source.loc[source[0]=='col', 1] = 'COL'
source.loc[source[0]=='col', 2] = 'https://www.catalogueoflife.org/data/taxon/'
source.loc[source[0]=='col', 3] = args.citation
source.loc[source[0]=='col', 4] = 'https://www.catalogueoflife.org/data/download'
source.loc[source[0]=='col', 5] = args.date

source.to_csv('./v2/source-data/sources.csv', sep='\t', header=None, index=None)

print('完成')
import numpy as np
import pandas as pd
from datetime import date

today = date.today()
today_str = today.strftime("%Y%m%d")

df = pd.read_table('/Users/taibif/Documents/04-TaiCOL/COL_20230327/Taxon.tsv', sep='\t', usecols=['dwc:taxonID', 'dwc:acceptedNameUsageID','dwc:scientificName','dwc:taxonRank','dwc:parentNameUsageID','dwc:scientificNameAuthorship','dwc:taxonomicStatus'])
df['common_name_c'] = None
df['kingdom'] = None
df['phylum'] = None
df['class'] = None
df['order'] = None
df['family'] = None
df['genus'] = None
df['simple_name'] = None

df = df.replace({np.nan: None})

df['simple_name'] = df[['dwc:scientificName','dwc:scientificNameAuthorship']].apply(lambda x: x['dwc:scientificName'].replace(x['dwc:scientificNameAuthorship'],'') if x['dwc:scientificName'] and x['dwc:scientificNameAuthorship'] else x['dwc:scientificName'], axis=1)
df["simple_name"]  = df["simple_name"].str.strip()

# 把階層串回來
id_name_dict = dict(zip(df['dwc:taxonID'], df['dwc:scientificName']))
id_rank_dict = dict(zip(df['dwc:taxonID'], df['dwc:taxonRank']))
parent_dict = dict(zip(df['dwc:taxonID'], df['dwc:parentNameUsageID']))

df = df.replace({np.nan: None})

def find_parent(x, i):
    value = parent_dict.get(x)
    if value: 
        parent_rank = id_rank_dict.get(value)
        if parent_rank in ['genus', 'family', 'order', 'class', 'phylum', 'kingdom']:
            parent_name = id_name_dict.get(value)
            df.loc[i, parent_rank] = parent_name
        if parent_rank not in ['kingdom', None]:
            find_parent(value, i) 

for i in df.index: #4817999
    key = df.iloc[i]['dwc:acceptedNameUsageID'] if df.iloc[i]['dwc:acceptedNameUsageID'] else df.iloc[i]['dwc:taxonID']
    find_parent(key,i)
    if i % 1000 == 0:
        print(i)


df = df[['dwc:taxonID','dwc:acceptedNameUsageID','dwc:scientificName','dwc:taxonID','dwc:acceptedNameUsageID', 
         'common_name_c', 'dwc:taxonRank', 'genus', 'family', 'order', 'class', 'phylum', 'kingdom', 'simple_name','dwc:taxonomicStatus']]

df = df.replace({np.nan: None})

df.to_csv(f'../source-data/source_col_{today_str}.csv', sep='\t', header=None, index=False)

# update source.csv if needed

source = pd.read_table('../source-data/sources.csv', sep='\t', header=None)
# id 不可動
# source = source.append({0:'col'},ignore_index=True)
# name
source.loc[source[0]=='col',1] = 'COL' 
# url_base
source.loc[source[0]=='col',2] = 'http://www.catalogueoflife.org/data/taxon/'
# citation
source.loc[source[0]=='col',3] = 'Bánki, O., Roskov, Y., Döring, M., Ower, G., Vandepitte, L., Hobern, D., Remsen, D., Schalk, P., DeWalt, R. E., Keping, M., Miller, J., Orrell, T., Aalbu, R., Abbott, J., Adlard, R., Adriaenssens, E. M., Aedo, C., Aescht, E., Akkari, N., et al. (2023). Catalogue of Life Checklist (Version 2023-03-09). Catalogue of Life. https://doi.org/10.48580/dfrt'
# url
source.loc[source[0]=='col',4] = 'https://www.catalogueoflife.org/data/download'
# version 下載檔案上的日期
source.loc[source[0]=='col',5] = '2023-03-09'

source.to_csv('../source-data/sources.csv', sep='\t', header=None, index=None)



